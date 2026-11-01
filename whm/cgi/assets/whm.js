/* Worker Supervisor - WHM pages. Progressive: without it every row shows and every field is enabled. */
(function () {
  'use strict';

  // Overview: status filter and account search, both client-side over the rendered rows.
  var bar = document.querySelector('[data-wsv="filters"]');
  var table = document.querySelector('[data-wsv="workers"]');
  if (bar && table) {
    var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr[data-group]'));
    var noMatch = table.querySelector('[data-wsv="no-match"]');
    var search = bar.querySelector('[data-wsv="search"]');
    var buttons = Array.prototype.slice.call(bar.querySelectorAll('button[data-group]'));
    var group = 'all';

    var apply = function () {
      var query = search.value.trim().toLowerCase();
      var shown = 0;
      rows.forEach(function (tr) {
        var visible = (group === 'all' || tr.getAttribute('data-group') === group)
          && tr.getAttribute('data-user').indexOf(query) !== -1;
        tr.hidden = !visible;
        shown += visible ? 1 : 0;
      });
      noMatch.hidden = shown > 0;
    };

    buttons.forEach(function (button) {
      button.addEventListener('click', function () {
        group = button.getAttribute('data-group');
        buttons.forEach(function (b) { b.setAttribute('aria-pressed', b === button ? 'true' : 'false'); });
        apply();
      });
    });
    search.addEventListener('input', apply);
    bar.hidden = false;
  }

  // Settings: the template boxes of an override only count when "Override the templates" is checked.
  var toggle = document.querySelector('[data-wsv="templates-toggle"]');
  var set = document.querySelector('[data-wsv="templates-set"]');
  if (toggle && set) {
    var sync = function () { set.disabled = !toggle.checked; };
    toggle.addEventListener('change', sync);
    sync();
  }
})();
