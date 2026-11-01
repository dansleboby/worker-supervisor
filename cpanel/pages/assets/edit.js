/* Worker Supervisor - create / edit a worker (edit.live.php). */
(function () {
  'use strict';

  var B = window.WSV;
  var root = document.getElementById('wsv-edit');
  if (!B || !root) {
    return;
  }
  var t = B.t;
  var el = B.el;
  var part = function (name) { return root.querySelector('[data-wsv="' + name + '"]'); };

  var DEBOUNCE_MS = 400;
  var FIELDS = [
    'name', 'template', 'folder', 'command', 'processes', 'autostart', 'autorestart',
    'startsecs', 'startretries', 'stopwaitsecs', 'redirect_stderr'
  ];
  var CHECKBOXES = { autostart: true, redirect_stderr: true };
  var ADVANCED = { autostart: true, autorestart: true, startsecs: true, startretries: true, stopwaitsecs: true, redirect_stderr: true };
  // Shown above the form: workers_limit carries field "name" but is about the account.
  var FORM_LEVEL = { workers_limit: true, input_invalid: true };
  var ICON = { error: 'remove-sign', warning: 'warning-sign', info: 'info-sign' };

  var form = part('form');
  var f = {};
  FIELDS.concat(['expert']).forEach(function (field) {
    f[field] = document.getElementById('wsv-f-' + field);
    // Hints stay described; issue ids are added on top of these.
    f[field].setAttribute('data-describedby', f[field].getAttribute('aria-describedby') || '');
  });
  var tabs = Array.prototype.slice.call(root.querySelectorAll('[role="tab"]'));

  var original = new URLSearchParams(location.search).get('name') || null;
  var templates = [];
  var limits = null;
  var state = {
    ready: false,
    mode: 'form',
    template: '',        // template selected before the current change, for the prefill rule
    issues: [],
    checked: false,      // state.issues hold a validation result, not the aftermath of a failed call
    // A new, untouched form shows only account-level issues, not "name is empty" and the like.
    pristine: original === null,
    pending: false,      // a validation is scheduled or in flight
    switching: false,
    saving: false
  };
  var alerts = { load: null, action: null };
  var shown = { alerts: null, checks: null, marks: null };
  var seq = 0;           // id of the latest validation; older replies are dropped
  var timer = null;

  function templateById(id) {
    return templates.filter(function (tp) { return tp.id === id; })[0] || null;
  }

  function known(key, params) {
    var text = t(key, params);
    return text === key ? null : text;
  }

  function hasErrors(issues) {
    return issues.some(function (i) { return i.level === 'error'; });
  }

  function params(mode) {
    var p = { mode: mode };
    if (mode === 'expert') {
      p.template = f.template.value;
      p.expert_text = f.expert.value;
    } else {
      // Every field, every time: an edit keeps the stored value of any key left out.
      FIELDS.forEach(function (field) {
        p[field] = CHECKBOXES[field] ? (f[field].checked ? '1' : '0') : f[field].value;
      });
    }
    p.original_name = original || undefined;
    return p;
  }

  /** Defaults of a new worker, as in the core's Worker. */
  function defaults() {
    var first = templates[0] || { id: '', command: '', fixed_processes: null };
    return {
      name: '', template: first.id, folder: (window.WSV_CONTEXT || {}).home || '', command: first.command,
      processes: first.fixed_processes || 1, autostart: true, autorestart: 'true', startsecs: 10,
      startretries: 30, stopwaitsecs: Math.min(3600, limits.max_stopwaitsecs), redirect_stderr: true
    };
  }

  function fillForm(w) {
    // A stored template the plan no longer allows stays selectable; validation flags it.
    var listed = Array.prototype.some.call(f.template.options, function (o) { return o.value === w.template; });
    if (w.template && !listed) {
      f.template.appendChild(el('option', { value: w.template, text: known('template.' + w.template) || w.template }));
    }
    FIELDS.forEach(function (field) {
      if (CHECKBOXES[field]) {
        f[field].checked = !!w[field];
      } else {
        f[field].value = String(w[field]);
      }
    });
    state.template = w.template;
    applyTemplate();
  }

  function applyTemplate() {
    var id = f.template.value;
    var fixed = (templateById(id) || {}).fixed_processes;
    if (fixed) {
      f.processes.value = String(fixed);
    }
    f.processes.disabled = !!fixed;
    part('deploy-hint').textContent = known('deploy_hint.' + id) || '';
    part('processes-help').textContent = fixed
      ? t('edit.processes_fixed_help', { value: fixed })
      : t('edit.processes_help', { max: limits.max_processes });
    part('expert-template').textContent = t('edit.expert_template', { template: known('template.' + id) || id });
  }

  /** Prefills the command unless the user has written their own. */
  function onTemplateChange() {
    var prev = templateById(state.template);
    var next = templateById(f.template.value);
    var command = f.command.value;
    if (next && (command.trim() === '' || (prev && command === prev.command))) {
      f.command.value = next.command;
    }
    state.template = f.template.value;
    applyTemplate();
  }

  function onEdit(e) {
    if (!state.ready) {
      return;
    }
    if (e.target === f.template) {
      onTemplateChange();
    }
    alerts.action = null;
    clearTimeout(timer);
    seq++;
    state.pending = true;
    timer = setTimeout(function () { validate(false); }, DEBOUNCE_MS);
    render();
  }

  /** initial: the page-load check of a new worker, whose issues stay hidden until the user edits. */
  function validate(initial) {
    clearTimeout(timer);
    var mine = ++seq;
    state.pending = true;
    render();
    B.api('validate_worker', params(state.mode)).then(function (r) {
      if (mine !== seq) {
        return;
      }
      state.pending = false;
      take(r, !initial);
      render();
    });
  }

  /**
   * Records a validate reply; a failed call leaves no known issues, so Save lets the server decide.
   * Revealing only on a reply keeps a new form from flashing issues about input already changed.
   */
  function take(r, reveal) {
    if (reveal) {
      state.pristine = false;
    }
    if (r.status) {
      state.issues = r.data.issues || [];
      state.checked = true;
    } else {
      state.issues = [];
      state.checked = false;
      alerts.action = B.errorText(r);
    }
    return !!r.status;
  }

  /**
   * Converts the current mode's input through validate_worker. To Expert needs text to show; back
   * to Form needs a worker free of errors, so a half-fixed text is never silently dropped.
   */
  function switchTo(target) {
    if (!state.ready || target === state.mode || state.switching || state.saving) {
      return;
    }
    clearTimeout(timer);
    var mine = ++seq;
    state.switching = true;
    state.pending = true;
    alerts.action = null;
    render();
    B.api('validate_worker', params(state.mode)).then(function (r) {
      state.switching = false;
      // Input typed meanwhile: its own validation is on the way and the switch is abandoned.
      if (mine !== seq) {
        render();
        return;
      }
      state.pending = false;
      if (take(r, true)) {
        var blocked = target === 'expert' ? r.data.expert === null : r.data.worker === null || hasErrors(state.issues);
        if (blocked) {
          alerts.action = t('edit.switch_blocked');
        } else {
          state.mode = target;
          if (target === 'form') {
            fillForm(r.data.worker);
          } else {
            f.expert.value = r.data.expert;
            // Saving now goes through the text, which does not always round-trip (an empty name).
            validate(false);
            return;
          }
        }
      }
      render();
    });
  }

  function save() {
    if (part('save').disabled) {
      return;
    }
    clearTimeout(timer);
    seq++;
    state.saving = true;
    alerts.action = null;
    render();
    B.api('save_worker', params(state.mode)).then(function (r) {
      if (r.status) {
        location.href = 'index.live.php';
        return;
      }
      state.saving = false;
      state.pristine = false;
      var error = r.data && r.data.error;
      if (error && error.code === 'validation_failed') {
        state.issues = error.issues || [];
        state.checked = true;
      }
      alerts.action = B.errorText(r);
      render();
      focusFirstError();
    });
  }

  function fieldOf(issue) {
    return issue.field === 'expert_text' ? 'expert' : issue.field;
  }

  function focusFirstError() {
    var issue = state.issues.filter(function (i) { return i.level === 'error' && !FORM_LEVEL[i.code]; })[0];
    var field = issue && (state.mode === 'expert' ? 'expert' : fieldOf(issue));
    if (!field || !f[field] || f[field].disabled) {
      return;
    }
    if (ADVANCED[field]) {
      part('advanced').open = true;
    }
    f[field].focus();
  }

  function render() {
    renderAlerts();
    renderTabs();
    renderChecks();
    var blocked = !state.ready || state.pending || state.switching || state.saving || hasErrors(state.issues);
    part('save').disabled = blocked;
    part('fields').disabled = state.saving;
    part('busy').textContent = state.saving ? t('edit.saving') : state.pending ? t('edit.checking') : '';
  }

  function renderAlerts() {
    var list = [alerts.load, alerts.action].filter(Boolean).map(function (text) { return ['danger', text]; });
    state.issues.forEach(function (i) {
      if (FORM_LEVEL[i.code]) {
        list.push([i.level === 'error' ? 'danger' : 'warning', t('issue.' + i.code, i.params || {})]);
      }
    });
    // A live region: rewriting it unchanged would announce the same message again.
    var sig = JSON.stringify(list);
    if (sig !== shown.alerts) {
      shown.alerts = sig;
      part('alerts').replaceChildren.apply(part('alerts'), list.map(function (a) { return B.alertBox(a[0], a[1]); }));
    }
  }

  function renderTabs() {
    tabs.forEach(function (tab) {
      var on = tab.getAttribute('data-mode') === state.mode;
      tab.setAttribute('aria-selected', on ? 'true' : 'false');
      tab.tabIndex = on ? 0 : -1;
    });
    document.getElementById('wsv-panel-form').hidden = state.mode !== 'form';
    document.getElementById('wsv-panel-expert').hidden = state.mode !== 'expert';
    tabs[0].parentNode.setAttribute('aria-busy', state.switching ? 'true' : 'false');
  }

  function renderChecks() {
    var listed = state.pristine ? [] : state.issues.filter(function (i) { return !FORM_LEVEL[i.code]; });
    var clean = state.checked && !state.pristine && !state.issues.some(function (i) { return i.level !== 'info'; });
    markFields(listed);
    var sig = JSON.stringify([listed, clean]);
    if (sig === shown.checks) {
      return;
    }
    shown.checks = sig;

    var items = listed.map(function (issue, n) {
      var level = levelOf(issue);
      return el('li', { id: 'wsv-issue-' + n, class: 'wsv-issue wsv-issue--' + level }, [
        el('span', { class: 'glyphicon glyphicon-' + ICON[level], 'aria-hidden': 'true' }),
        el('span', { class: 'wsv-sr', text: t('edit.level_' + level) + ' ' }),
        issueText(issue)
      ]);
    });
    var box = part('checks');
    box.replaceChildren.apply(box, [
      items.length ? el('ul', { class: 'wsv-issues' }, items) : null,
      clean ? el('p', { class: 'wsv-checks-ok' }, [
        el('span', { class: 'glyphicon glyphicon-ok-sign', 'aria-hidden': 'true' }), ' ' + t('edit.checks_ok')
      ]) : null
    ].filter(Boolean));
  }

  /** Error/warning state and aria-describedby of each control; in Expert every issue is about the text. */
  function markFields(listed) {
    var sig = JSON.stringify([listed, state.mode]);
    if (sig === shown.marks) {
      return;
    }
    shown.marks = sig;

    var byField = {};
    listed.forEach(function (issue, n) {
      var field = state.mode === 'expert' ? 'expert' : fieldOf(issue);
      var own = byField[field] || (byField[field] = { level: null, ids: [] });
      own.ids.push('wsv-issue-' + n);
      var level = levelOf(issue);
      if (level === 'error' || (level === 'warning' && own.level !== 'error')) {
        own.level = level;
      }
    });

    Object.keys(f).forEach(function (field) {
      var own = byField[field] || { level: null, ids: [] };
      var group = f[field].closest('.form-group');
      group.classList.toggle('has-error', own.level === 'error');
      group.classList.toggle('has-warning', own.level === 'warning');
      if (own.level === 'error') {
        f[field].setAttribute('aria-invalid', 'true');
      } else {
        f[field].removeAttribute('aria-invalid');
      }
      var describedBy = [f[field].getAttribute('data-describedby')].concat(own.ids).filter(Boolean).join(' ');
      if (describedBy) {
        f[field].setAttribute('aria-describedby', describedBy);
      } else {
        f[field].removeAttribute('aria-describedby');
      }
      if (own.level && ADVANCED[field]) {
        part('advanced').open = true;
      }
    });
  }

  function levelOf(issue) {
    return ICON[issue.level] ? issue.level : 'info';
  }

  function issueText(issue) {
    var message = t('issue.' + issue.code, issue.params || {});
    if (issue.line) {
      return t('edit.issue_line', { line: issue.line, message: message });
    }
    var field = fieldOf(issue);
    var label = f[field] ? t('edit.' + (field === 'expert' ? 'expert_text' : field)) : null;
    // Some messages already name their field (laravel_needs_autorestart).
    return label && message.indexOf(label) === -1 ? t('edit.issue_field', { field: label, message: message }) : message;
  }

  form.addEventListener('input', onEdit);
  // Text fields also fire change on blur, which would re-check unchanged input.
  form.addEventListener('change', function (e) {
    if (e.target.type === 'checkbox') {
      onEdit(e);
    }
  });
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    save();
  });
  // The command is one line; the textarea only exists to wrap long commands.
  f.command.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') {
      e.preventDefault();
    }
  });
  tabs.forEach(function (tab) {
    tab.addEventListener('click', function () { switchTo(tab.getAttribute('data-mode')); });
    // Manual activation: arrows move the focus, Enter or Space switches (a switch is a server call).
    tab.addEventListener('keydown', function (e) {
      var i = tabs.indexOf(tab);
      var next = { ArrowRight: i + 1, ArrowLeft: i - 1, Home: 0, End: tabs.length - 1 }[e.key];
      if (next !== undefined) {
        e.preventDefault();
        tabs[(next + tabs.length) % tabs.length].focus();
      }
    });
  });

  Promise.all([
    B.api('list_templates'),
    original === null ? null : B.api('get_worker', { name: original })
  ]).then(function (res) {
    var failed = [res[0], res[1]].filter(function (r) { return r && !r.status; })[0];
    part('loading').hidden = true;
    if (failed) {
      alerts.load = B.errorText(failed);
      part('back').hidden = false;
      render();
      return;
    }
    templates = res[0].data.templates || [];
    limits = res[0].data.limits;
    templates.forEach(function (tp) {
      f.template.appendChild(el('option', { value: tp.id, text: known('template.' + tp.id) || tp.id }));
    });
    f.processes.max = limits.max_processes;
    f.stopwaitsecs.max = limits.max_stopwaitsecs;
    part('stopwait-help').textContent = t('edit.stopwaitsecs_help', { max: limits.max_stopwaitsecs });
    if (res[1]) {
      fillForm(res[1].data.worker);
      f.expert.value = res[1].data.expert;
    } else {
      fillForm(defaults());
    }
    form.hidden = false;
    state.ready = true;
    validate(original === null);
  });
})();
