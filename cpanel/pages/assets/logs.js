/* Worker Supervisor - log viewer (logs.live.php). */
(function () {
  'use strict';

  var B = window.WSV;
  var root = document.getElementById('wsv-logs');
  if (!B || !root) {
    return;
  }
  var t = B.t;
  var el = B.el;
  var part = function (name) { return root.querySelector('[data-wsv="' + name + '"]'); };

  var REFRESH_MS = 3000;
  var BOTTOM_SLACK = 24;   // px from the bottom still counted as "at the bottom"

  var name = new URLSearchParams(location.search).get('name') || '';
  var ui = {
    heading: part('heading'), alerts: part('alerts'), loading: part('loading'), viewer: part('viewer'),
    processes: part('processes'), streams: part('streams'), lines: part('lines'), auto: part('auto'),
    buttons: part('buttons'), confirm: part('confirm'), confirmText: part('confirm-text'), text: part('text')
  };
  var state = { process: null, stream: 'out', files: [], text: '' };
  var seq = 0;            // id of the latest fetch; older replies are dropped
  var timer = null;
  var clearing = false;

  ui.heading.textContent = t('logs.heading', { name: name });

  function showAlert(kind, message) {
    ui.alerts.textContent = '';
    if (message) {
      ui.alerts.appendChild(B.alertBox(kind, message));
    }
  }

  function atBottom() {
    var p = ui.text;
    return p.scrollHeight - p.scrollTop - p.clientHeight <= BOTTOM_SLACK;
  }

  function processIndexes() {
    var seen = {};
    return state.files.map(function (f) { return f.process; }).filter(function (p) {
      return seen[p] ? false : (seen[p] = true);
    });
  }

  function hasStderr() {
    return state.files.some(function (f) { return f.stream === 'err'; });
  }

  function pressed(button, on) {
    button.setAttribute('aria-pressed', on ? 'true' : 'false');
  }

  function renderControls() {
    var indexes = processIndexes();
    ui.processes.textContent = '';
    indexes.forEach(function (p) {
      var tab = el('button', {
        type: 'button', class: 'wsv-tab', 'data-process': p, text: t('logs.process', { n: Number(p) + 1 }),
        'aria-pressed': p === state.process ? 'true' : 'false',
        onclick: function () { state.process = p; renderControls(); load(true); }
      });
      ui.processes.appendChild(tab);
    });
    ui.processes.hidden = indexes.length < 2;

    var split = hasStderr();
    if (!split) {
      state.stream = 'out';
    }
    ui.streams.hidden = !split;
    ui.streams.textContent = '';
    if (split) {
      ['out', 'err'].forEach(function (stream) {
        var button = el('button', {
          type: 'button', class: 'btn btn-default btn-sm', 'data-stream': stream, text: t('logs.stream_' + stream),
          'aria-pressed': stream === state.stream ? 'true' : 'false',
          onclick: function () { state.stream = stream; renderControls(); load(true); }
        });
        ui.streams.appendChild(button);
      });
    }
  }

  function setText(text, stick) {
    state.text = text;
    ui.text.textContent = text === '' ? t('logs.empty') : text;
    ui.text.classList.toggle('wsv-muted', text === '');
    ui.text.setAttribute('aria-label', t('logs.text_label', { name: name, n: Number(state.process || 0) + 1 }));
    if (stick) {
      ui.text.scrollTop = ui.text.scrollHeight;
    }
  }

  /** Fetches the current selection. `jump` forces the view to the bottom (new selection, first load). */
  function load(jump) {
    var mine = ++seq;
    return B.api('get_logs', {
      name: name, process: Number(state.process || 0), stream: state.stream, lines: ui.lines.value
    }).then(function (r) {
      if (mine !== seq) {
        return;
      }
      ui.loading.hidden = true;
      if (r.status !== 1) {
        showAlert('danger', B.errorText(r));
        if (!state.files.length) {
          ui.viewer.hidden = true;
        }
        return;
      }
      showAlert(null, null);
      ui.viewer.hidden = false;
      var first = state.process === null;
      state.files = r.data.files || [];
      if (first || processIndexes().indexOf(state.process) === -1) {
        state.process = processIndexes()[0] || '00';
        renderControls();
        if (!first) {
          return load(true);
        }
      } else if (hasStderr() !== !ui.streams.hidden) {
        renderControls();
      }
      var stick = jump || atBottom();
      if (r.data.text !== state.text || jump) {
        setText(r.data.text || '', stick);
      }
    });
  }

  function schedule() {
    clearInterval(timer);
    timer = null;
    if (ui.auto.checked && !document.hidden && !clearing) {
      timer = setInterval(function () { load(false); }, REFRESH_MS);
    }
  }

  function download() {
    var blob = new Blob([state.text], { type: 'text/plain;charset=utf-8' });
    var url = URL.createObjectURL(blob);
    var link = el('a', { href: url, download: name + '_' + state.process + '_' + state.stream + '.log', hidden: true });
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
  }

  function setClearing(on) {
    clearing = on;
    ui.confirm.hidden = !on;
    ui.buttons.hidden = on;
    if (on) {
      ui.confirmText.textContent = t('logs.confirm_clear', { name: name });
      part('confirm-no').focus();
    } else {
      part('clear').focus();
    }
    schedule();
  }

  function doClear() {
    var yes = part('confirm-yes');
    yes.disabled = true;
    B.api('clear_logs', { name: name }).then(function (r) {
      yes.disabled = false;
      setClearing(false);
      if (r.status !== 1) {
        showAlert('danger', B.errorText(r));
        return;
      }
      showAlert(null, null);
      load(true);
    });
  }

  part('refresh').addEventListener('click', function () { load(false); });
  part('download').addEventListener('click', download);
  part('clear').addEventListener('click', function () { setClearing(true); });
  part('confirm-yes').addEventListener('click', doClear);
  part('confirm-no').addEventListener('click', function () { setClearing(false); });
  ui.confirm.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      setClearing(false);
    }
  });
  ui.lines.addEventListener('change', function () { load(true); });
  ui.auto.addEventListener('change', schedule);
  document.addEventListener('visibilitychange', function () {
    schedule();
    if (!document.hidden && ui.auto.checked) {
      load(false);
    }
  });

  if (name === '') {
    ui.loading.hidden = true;
    showAlert('danger', t('error.bad_request'));
    return;
  }
  load(true);
  schedule();
})();
