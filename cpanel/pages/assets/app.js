/* Worker Supervisor - helpers shared by every page: window.WSV. No build step, no dependencies. */
(function () {
  'use strict';

  var catalog = window.WSV_I18N || {};
  var context = window.WSV_CONTEXT || {};
  var has = function (obj, key) { return Object.prototype.hasOwnProperty.call(obj, key); };

  /** Same rule as I18n::t(): {name} placeholders, one pass, unknown keys come back as the key. */
  function t(key, params) {
    var text = has(catalog, key) ? catalog[key] : key;
    if (!params) {
      return text;
    }
    return text.replace(/\{(\w+)\}/g, function (match, name) {
      return has(params, name) ? String(params[name]) : match;
    });
  }

  /**
   * Calls UAPI WorkerSupervisor::<fn>. Always resolves, to {status, data, errors}; a network
   * failure or a non-JSON reply (an expired session serves the login page) becomes errors
   * ['request_failed'].
   */
  function api(fn, params) {
    var session = location.pathname.match(/^\/cpsess\d+\//);
    var body = new URLSearchParams();
    Object.keys(params || {}).forEach(function (key) {
      if (params[key] !== undefined && params[key] !== null) {
        body.append(key, String(params[key]));
      }
    });
    var failed = { status: 0, data: null, errors: ['request_failed'] };
    if (!session) {
      return Promise.resolve(failed);
    }
    return fetch(session[0] + 'execute/WorkerSupervisor/' + encodeURIComponent(fn), {
      method: 'POST',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
      body: body
    })
      .then(function (res) { return res.json(); })
      .then(function (r) { return { status: r.status, data: r.data, errors: r.errors }; })
      .catch(function () { return failed; });
  }

  /** Message for a failed api() result; codes without a catalog entry get the generic one. */
  function errorText(result) {
    var error = result && result.data && result.data.error;
    var code = (error && error.code) || (result && result.errors && result.errors[0]);
    var key = 'error.' + code;
    return has(catalog, key) ? t(key, (error && error.params) || {}) : t('error.internal');
  }

  /**
   * createElement shorthand. attrs: `text` sets textContent, `on<event>` adds a listener, false or
   * null skips the attribute. Children are nodes or strings (inserted as text, never as HTML).
   */
  function el(tag, attrs, children) {
    var node = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (key) {
      var value = attrs[key];
      if (value === null || value === undefined || value === false) {
        return;
      }
      if (key === 'text') {
        node.textContent = value;
      } else if (key.slice(0, 2) === 'on') {
        node.addEventListener(key.slice(2), value);
      } else {
        node.setAttribute(key, value === true ? '' : value);
      }
    });
    (children || []).forEach(function (child) {
      if (child !== null && child !== undefined && child !== false) {
        node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
      }
    });
    return node;
  }

  /** Bootstrap alert carrying plain text. */
  function alertBox(kind, text) {
    return el('div', { class: 'alert alert-' + kind }, [
      el('span', { class: 'glyphicon glyphicon-' + (kind === 'danger' ? 'remove-sign' : 'exclamation-sign') }),
      el('div', { class: 'alert-message', text: text })
    ]);
  }

  /** Display form of a folder: the account's home becomes ~. */
  function folderLabel(folder) {
    var home = context.home;
    if (home && folder === home) {
      return '~';
    }
    if (home && folder.indexOf(home + '/') === 0) {
      return '~' + folder.slice(home.length);
    }
    return folder;
  }

  var clockOffset = typeof context.now === 'number' ? context.now - Date.now() / 1000 : 0;

  /** Server time in seconds: the page's render time plus the time elapsed in the browser. */
  function serverNow() {
    return Date.now() / 1000 + clockOffset;
  }

  window.WSV = {
    t: t,
    api: api,
    errorText: errorText,
    el: el,
    alertBox: alertBox,
    folderLabel: folderLabel,
    serverNow: serverNow,
    locale: context.locale || 'en'
  };
})();
