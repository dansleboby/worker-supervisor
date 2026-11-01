/* Worker Supervisor - worker list (index.live.php). */
(function () {
  'use strict';

  var B = window.WSV;
  var root = document.getElementById('wsv-list');
  if (!B || !root) {
    return;
  }
  var t = B.t;
  var el = B.el;
  var part = function (name) { return root.querySelector('[data-wsv="' + name + '"]'); };

  var REFRESH_MS = 5000;
  // Start/Stop-type buttons per status; Logs, Edit and Delete are always offered. A suspended,
  // feature-off or admin-stopped worker would be refused a start by the server anyway.
  var CONTROLS = {
    running: ['restart', 'stop'],
    starting: ['restart', 'stop'],
    stopped: ['start'],
    failed: ['start']
  };
  var PILL = {
    running: 'ok', starting: 'busy', stopping: 'busy', stopped: 'off', failed: 'bad',
    suspended: 'warn', admin_stopped: 'warn', feature_off: 'warn'
  };
  var HINTS = { suspended: true, admin_stopped: true, feature_off: true };
  var API = { start: 'start_worker', stop: 'stop_worker', restart: 'restart_worker', delete: 'delete_worker' };
  // The server only queues start/stop/restart for supervisord, so the row shows the status the
  // action leads to until a refresh started after the call has returned.
  var LEADS_TO = { start: 'starting', stop: 'stopping', restart: 'starting' };

  var tbody = part('rows');
  var workers = null;
  var rows = new Map();     // name -> {tr, sig, w}
  var busy = {};            // name -> true while an action call is in flight
  var transition = {};      // name -> {status, seq}
  var confirming = null;    // name whose Delete awaits confirmation
  var focusAfter = null;    // {name, action}: control to focus once that row is rebuilt
  var errors = { load: null, action: null };
  var shownErrors = '';
  var seq = 0;              // id of the latest list_workers call; older replies are dropped
  var timer = null;
  var waitingForVisible = false;

  function refresh() {
    clearTimeout(timer);
    var mine = ++seq;
    B.api('list_workers').then(function (r) {
      if (mine !== seq) {
        return;
      }
      if (r.status) {
        errors.load = null;
        workers = r.data.workers;
        Object.keys(transition).forEach(function (name) {
          if (transition[name].seq < mine) {
            delete transition[name];
          }
        });
        renderUsage(r.data.usage);
      } else {
        errors.load = B.errorText(r);
      }
      render();
      schedule();
    });
  }

  function schedule() {
    clearTimeout(timer);
    timer = setTimeout(function () {
      if (document.hidden) {
        waitingForVisible = true;
      } else {
        refresh();
      }
    }, REFRESH_MS);
  }

  document.addEventListener('visibilitychange', function () {
    if (!document.hidden && waitingForVisible) {
      waitingForVisible = false;
      refresh();
    }
  });

  function act(name, action) {
    if (busy[name]) {
      return;
    }
    busy[name] = true;
    confirming = null;
    errors.action = null;
    render();
    B.api(API[action], { name: name }).then(function (r) {
      delete busy[name];
      if (!r.status) {
        errors.action = B.errorText(r);
        render();
        return;
      }
      if (action === 'delete') {
        // Deleting is synchronous on the server: the next list is already without it.
        workers = workers.filter(function (w) { return w.name !== name; });
        render();
        refresh();
        return;
      }
      transition[name] = { status: LEADS_TO[action], seq: seq };
      render();
      schedule();
    });
  }

  var PALETTE_SIZE = 8;
  var MAX_SLOTS = 20;
  var usageSig = null;

  /** Rebuilt only when the numbers or the worker list changed. */
  function buildUsage(u, list) {
    // Explicit fields: the server does not keep object key order stable between calls.
    var sig = JSON.stringify([
      u.processes, u.max_processes, u.workers, u.max_workers, !!u.over_limit,
      list.map(function (w) { return [w.name, w.processes]; })
    ]);
    if (sig === usageSig) {
      return;
    }
    usageSig = sig;

    var colour = function (i) { return 'wsv-c' + (i % PALETTE_SIZE); };
    // Over the limit the configured total exceeds the maximum: scale so the bar fills exactly.
    var total = u.over_limit
      ? list.reduce(function (sum, w) { return sum + w.processes; }, 0)
      : u.max_processes;
    var pct = function (n) { return total > 0 ? (100 * n) / total : 0; };

    var bar = el('div', {
      class: 'wsv-usage-bar' + (u.over_limit ? ' wsv-usage-bar--over' : ''),
      role: 'img',
      'aria-label': t('list.usage_bar_label', { used: u.processes, max: u.max_processes })
    }, list.map(function (w, i) {
      var seg = el('span', { class: colour(i), title: w.name + ': ' + w.processes });
      seg.style.width = pct(w.processes) + '%';
      return seg;
    }));

    var slotCount = Math.min(MAX_SLOTS, Math.max(u.max_workers, list.length));
    var slots = [];
    for (var i = 0; i < slotCount; i++) {
      var filled = i < list.length;
      slots.push(el('span', {
        class: 'wsv-usage-slot' + (filled ? ' ' + colour(i) : '') + (i >= u.max_workers ? ' wsv-usage-slot--extra' : ''),
        title: filled ? list[i].name : null
      }));
    }

    part('usage').replaceChildren(
      el('div', { class: 'wsv-usage-row' }, [
        el('span', { class: 'wsv-usage-label', text: t('list.usage_processes', { used: u.processes, max: u.max_processes }) }),
        bar
      ]),
      el('div', { class: 'wsv-usage-row' }, [
        el('span', { class: 'wsv-usage-label', text: t('list.usage_workers', { used: u.workers, max: u.max_workers }) }),
        el('div', { class: 'wsv-usage-slots', role: 'img', 'aria-label': t('list.usage_slots_label', { used: u.workers, max: u.max_workers }) }, slots)
      ]),
      el('div', { class: 'wsv-usage-legend' }, list.map(function (w, i) {
        return el('span', {}, [
          el('i', { class: 'wsv-usage-chip ' + colour(i), 'aria-hidden': 'true' }),
          w.name + ' ' + w.processes
        ]);
      }))
    );
  }

  function renderUsage(u) {
    var full = u.workers >= u.max_workers || u.processes >= u.max_processes;
    buildUsage(u, workers);
    part('usage').hidden = false;
    part('over-limit').hidden = !u.over_limit;
    part('limit-reached').hidden = !full || u.over_limit;

    var link = part('new');
    link.classList.toggle('disabled', full);
    if (full) {
      link.setAttribute('aria-disabled', 'true');
    } else {
      link.removeAttribute('aria-disabled');
    }
  }

  part('new').addEventListener('click', function (e) {
    if (this.getAttribute('aria-disabled') === 'true') {
      e.preventDefault();
    }
  });

  function render() {
    // The box is a live region: rewriting it on every refresh would announce the same error again.
    var messages = [errors.load, errors.action].filter(Boolean);
    if (messages.join('\n') !== shownErrors) {
      shownErrors = messages.join('\n');
      part('alerts').replaceChildren.apply(part('alerts'), messages.map(function (text) {
        return B.alertBox('danger', text);
      }));
    }

    if (workers === null) {
      return;
    }
    if (workers.length === 0) {
      rows.clear();
      tbody.replaceChildren(el('tr', {}, [el('td', { colspan: 5, class: 'wsv-muted', text: t('list.empty') })]));
      return;
    }

    var next = new Map();
    var ordered = workers.map(function (w) {
      var status = transition[w.name] ? transition[w.name].status : w.status;
      var sig = JSON.stringify([w, status, !!busy[w.name], confirming === w.name]);
      var old = rows.get(w.name);
      var entry = old && old.sig === sig ? old : { tr: buildRow(w, status), sig: sig, w: w };
      if (old && old !== entry && old.tr.parentNode === tbody) {
        swapRow(w.name, old.tr, entry.tr);
      }
      updateSince(entry);
      next.set(w.name, entry);
      return entry.tr;
    });
    rows = next;

    // Only rebuilt when rows come or go: moving a row would drop the keyboard focus inside it.
    var current = tbody.children;
    var same = current.length === ordered.length && ordered.every(function (tr, i) { return current[i] === tr; });
    if (!same) {
      tbody.replaceChildren.apply(tbody, ordered);
    }
  }

  /** Replaces a row in place and keeps the keyboard focus on the matching control. */
  function swapRow(name, oldTr, newTr) {
    var focused = document.activeElement;
    var hadFocus = oldTr.contains(focused);
    var wanted = focusAfter && focusAfter.name === name ? focusAfter.action : null;
    var action = wanted || (hadFocus && focused.getAttribute('data-action'));
    oldTr.replaceWith(newTr);
    if (hadFocus || wanted) {
      var target = (action && newTr.querySelector('[data-action="' + action + '"]:not([disabled])'))
        || newTr.querySelector('a, button:not([disabled])');
      if (target) {
        target.focus();
      }
    }
    if (wanted) {
      focusAfter = null;
    }
  }

  function buildRow(w, status) {
    var logs = 'logs.live.php?name=' + encodeURIComponent(w.name);
    var statusCell = el('td', {}, [
      el('span', { class: 'wsv-pill wsv-pill--' + (PILL[status] || 'off'), text: t('status.' + status) }),
      status === 'failed' && w.reason
        ? el('div', { class: 'wsv-why' }, [
          t('reason.' + w.reason.code, w.reason.params) + ' ',
          el('a', { href: logs, text: t('list.see_log') })
        ])
        : null,
      HINTS[status] ? el('div', { class: 'wsv-hint', text: t('status_hint.' + status) }) : null
    ]);

    return el('tr', { 'data-name': w.name }, [
      el('td', {}, [
        el('strong', { text: w.name }),
        el('div', { class: 'wsv-folder', title: w.folder, text: B.folderLabel(w.folder) })
      ]),
      statusCell,
      el('td', { text: w.running + ' / ' + w.processes }),
      el('td', { class: 'wsv-since' }),
      el('td', { class: 'wsv-actions' }, [confirming === w.name ? confirmBox(w) : actionBar(w, status, logs)])
    ]);
  }

  function actionBar(w, status, logs) {
    var disabled = !!busy[w.name];
    var button = function (action, label) {
      return el('button', {
        type: 'button', class: 'btn btn-default btn-sm', 'data-action': action, disabled: disabled,
        text: label || t('action.' + action),
        onclick: function () {
          if (action === 'delete') {
            confirming = w.name;
            focusAfter = { name: w.name, action: 'delete' };
            errors.action = null;
            render();
          } else {
            act(w.name, action);
          }
        }
      });
    };
    var link = function (action, href) {
      return el('a', { class: 'btn btn-default btn-sm', 'data-action': action, href: href, text: t('action.' + action) });
    };

    return el('div', { role: 'group', 'aria-label': t('list.actions_for', { name: w.name }), 'aria-busy': disabled ? 'true' : null },
      (CONTROLS[status] || []).map(function (action) { return button(action); }).concat([
        link('logs', logs),
        link('edit', 'edit.live.php?name=' + encodeURIComponent(w.name)),
        button('delete')
      ]));
  }

  function confirmBox(w) {
    var cancel = function () {
      confirming = null;
      focusAfter = { name: w.name, action: 'delete' };
      render();
    };
    return el('div', {
      class: 'wsv-confirm', role: 'group',
      onkeydown: function (e) {
        if (e.key === 'Escape') {
          cancel();
        }
      }
    }, [
      el('span', { text: t('list.confirm_delete', { name: w.name }) }),
      el('button', {
        type: 'button', class: 'btn btn-danger btn-sm', 'data-action': 'delete', text: t('action.delete'),
        onclick: function () { act(w.name, 'delete'); }
      }),
      el('button', { type: 'button', class: 'btn btn-default btn-sm', 'data-action': 'cancel', text: t('common.cancel'), onclick: cancel })
    ]);
  }

  function updateSince(entry) {
    var cell = entry.tr.querySelector('.wsv-since');
    var since = entry.w.since;
    if (typeof since !== 'number') {
      cell.textContent = '-';
      cell.removeAttribute('title');
      return;
    }
    cell.textContent = duration(B.serverNow() - since);
    cell.title = t('list.since_title', { date: new Date(since * 1000).toLocaleString(B.locale) });
  }

  function duration(seconds) {
    var s = Math.max(0, Math.floor(seconds));
    var d = Math.floor(s / 86400);
    var h = Math.floor((s % 86400) / 3600);
    var m = Math.floor((s % 3600) / 60);
    if (d > 0) {
      return t('duration.days', { d: d, h: h });
    }
    if (h > 0) {
      return t('duration.hours', { h: h, m: m });
    }
    return m > 0 ? t('duration.minutes', { m: m }) : t('duration.seconds', { s: s });
  }

  refresh();
})();
