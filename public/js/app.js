/* Task Manager front-end (jQuery + Bootstrap 4 + SortableJS). No build step needed. */
(function ($) {
  'use strict';
  var csrf = $('meta[name="csrf-token"]').attr('content');
  $.ajaxSetup({ headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' } });

  /* ---------- toasts ---------- */
  window.toast = function (msg, type) {
    var $t = $('<div class="toast-msg ' + (type || '') + '"></div>').text(msg).appendTo('#toasts');
    setTimeout(function () { $t.fadeOut(250, function () { $t.remove(); }); }, 3600);
  };
  function errText(xhr) {
    if (xhr.responseJSON && xhr.responseJSON.message) return xhr.responseJSON.message;
    if (xhr.status === 0) return 'Network problem. Check your connection and try again.';
    return 'Something went wrong (HTTP ' + xhr.status + ').';
  }
  window.errText = errText;

  /* ---------- theme ---------- */
  function setTheme(t) { document.documentElement.setAttribute('data-theme', t); try { localStorage.setItem('tm-theme', t); } catch (e) {} $('#themeBtn i').attr('class', t === 'dark' ? 'fas fa-sun' : 'fas fa-moon'); }
  $('#themeBtn').on('click', function () { setTheme(document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark'); if (window.refreshCharts) window.refreshCharts(); });
  setTheme(document.documentElement.getAttribute('data-theme') || 'dark');

  /* ---------- mobile sidebar ---------- */
  $('#menuBtn, #sidebarBackdrop').on('click', function () { $('#sidebar').toggleClass('open'); $('#sidebarBackdrop').toggleClass('show'); });

  /* ---------- delete confirmation modal (one shared modal) ---------- */
  $(document).on('click', '[data-delete-url]', function (e) {
    e.preventDefault();
    var $b = $(this);
    $('#deleteForm').attr('action', $b.data('delete-url'));
    $('#deleteName').text($b.data('delete-name') || 'this item');
    $('#deleteNote').text($b.data('delete-note') || 'This can be undone from the trash.');
    $('#deleteModal').data('row', $b.closest('.task-row').attr('id') || '').modal('show');
  });
  $('#deleteModal').on('shown.bs.modal', function () { $('#deleteConfirm').trigger('focus'); });
  $('#deleteForm').on('submit', function () { $('#deleteConfirm').prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Deleting…'); });

  /* ---------- mark completed / pending without a page reload ---------- */
  $(document).on('submit', 'form.toggle-form', function (e) {
    e.preventDefault();
    var $f = $(this), $row = $f.closest('.task-row'), $btn = $f.find('button').prop('disabled', true);
    $.ajax({ url: $f.attr('action'), method: 'POST', data: $f.serialize() })
      .done(function (r) {
        var done = r.status === 'completed';
        $row.toggleClass('is-completed', done).toggleClass('is-overdue', false);
        $row.find('.status-badge').attr('class', 'badge badge-' + r.badge + ' status-badge').html('<i class="fas ' + r.icon + '"></i> ' + r.label);
        $btn.attr('title', done ? 'Mark as pending' : 'Mark as completed');
        toast(r.message, 'ok');
        if ($row.data('filter-status')) { $row.fadeOut(250, function () { $row.remove(); }); }
      })
      .fail(function (xhr) { toast(errText(xhr), 'err'); })
      .always(function () { $btn.prop('disabled', false); });
  });

  /* ---------- drag & drop ordering ---------- */
  var list = document.getElementById('taskList');
  if (list && window.Sortable && list.dataset.sortable === '1') {
    Sortable.create(list, {
      handle: '.drag-handle', animation: 160, ghostClass: 'sortable-ghost', chosenClass: 'sortable-chosen', forceFallback: false,
      onEnd: function (ev) {
        if (ev.oldIndex === ev.newIndex) return;
        var ids = $(list).children('.task-row').map(function () { return $(this).data('id'); }).get();
        $.ajax({ url: list.dataset.reorderUrl, method: 'POST', data: { ids: ids } })
          .done(function () { toast('Order saved.', 'ok'); })
          .fail(function (xhr) { toast(errText(xhr), 'err'); });
      }
    });
  }

  /* ---------- small helpers ---------- */
  $('.auto-dismiss').delay(5000).fadeOut(400);
  $(document).on('click', '[data-fill-email]', function () { $('#email').val($(this).data('fill-email')); $('#password').val('password'); });
  $('#filterToggle').on('click', function () { $('#filters').collapse('toggle'); });
  $(document).on('input', 'textarea[data-count]', function () { $($(this).data('count')).text(this.value.length); }).find('textarea[data-count]').trigger('input');
  $('form.once').on('submit', function () { $(this).find('button[type=submit]').prop('disabled', true); });
})(jQuery);
