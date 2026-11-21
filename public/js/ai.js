/* AI assistant widget. Talks to /ai/chat and /ai/execute; every change is shown as an editable card first. */
(function ($) {
  'use strict';
  var $p = $('#aiPanel'); if (!$p.length) return;
  var $log = $('#aiLog'), $in = $('#aiInput'), loaded = false, busy = false, dirty = false;

  function open() { $p.addClass('open'); $('#aiFab').hide(); if (!loaded) load(); setTimeout(function () { $in.trigger('focus'); }, 250); }
  function close() { $p.removeClass('open'); $('#aiFab').show(); if (dirty) { location.reload(); } }
  $('#aiFab, #openAiSide').on('click', function (e) { e.preventDefault(); open(); });
  $('#aiClose').on('click', close);
  $('#aiWide').on('click', function () { $p.toggleClass('wide'); $(this).find('i').toggleClass('fa-expand fa-compress'); });
  $(document).on('keydown', function (e) { if (e.key === 'Escape' && $p.hasClass('open') && !$('.modal.show').length) close(); });

  function scroll() { $log.scrollTop($log[0].scrollHeight); }
  function bubble(role, text) { var $m = $('<div class="msg ' + role + '"></div>').text(text).appendTo($log); scroll(); return $m; }

  function field(f) {
    var $row = $('<div></div>'), name = f.key, v = f.value == null ? '' : f.value, $el;
    if (f.type === 'hidden') return $('<input type="hidden">').attr('name', name).val(v);
    if (f.type === 'select') { $el = $('<select class="custom-select custom-select-sm"></select>'); (f.options || []).forEach(function (o) { $('<option></option>').val(o.value).text(o.label).prop('selected', String(o.value) === String(v)).appendTo($el); }); }
    else if (f.type === 'textarea') { $el = $('<textarea class="form-control form-control-sm" rows="2"></textarea>').val(v); }
    else { $el = $('<input class="form-control form-control-sm">').attr('type', f.type === 'date' ? 'date' : 'text').val(v); }
    return $el.attr('name', name).attr('aria-label', f.label);
  }

  function card(p, messageId) {
    var $c = $('<div class="proposal"></div>').toggleClass('danger', !!p.danger).toggleClass('done', !!p.done).attr('data-pid', p.id);
    $('<div class="ph"></div>').append($('<i class="fas ' + p.icon + '"></i>')).append($('<span></span>').text(p.label + (p.target ? ' — ' + p.target : ''))).appendTo($c);
    if (p.done) { $('<div class="status text-success"></div>').html('<i class="fas fa-circle-check"></i> ').append(document.createTextNode(p.result || 'Done')).appendTo($c); return $c; }
    var $dl = $('<dl class="fields"></dl>');
    (p.fields || []).forEach(function (f) { if (f.type === 'hidden') { $c.append(field(f)); return; } $('<dt></dt>').text(f.label).appendTo($dl); $('<dd></dd>').append(field(f)).appendTo($dl); });
    if (p.action !== 'delete_task') $c.append($dl);
    var $ok = $('<button class="btn btn-sm ' + (p.danger ? 'btn-danger' : 'btn-primary') + '"><i class="fas fa-check mr-1"></i>Confirm</button>');
    var $no = $('<button class="btn btn-sm btn-light ml-2">Dismiss</button>');
    var $st = $('<div class="status"></div>');
    $ok.on('click', function () {
      var params = {}; $c.find('[name]').each(function () { params[this.name] = $(this).val(); });
      $ok.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');
      $.ajax({ url: $p.data('exec'), method: 'POST', data: { action: p.action, params: params, message_id: messageId || '', proposal_id: p.id } })
        .done(function (r) {
          dirty = true; $c.addClass('done'); $c.find('.fields, .btn').remove(); $c.find('input,select,textarea').remove();
          $st.addClass('text-success').html('<i class="fas fa-circle-check"></i> ').append(document.createTextNode(r.message));
          if (r.url) $st.append(' <a href="' + r.url + '">Open</a>');
          toast(r.message, 'ok');
        })
        .fail(function (xhr) { $ok.prop('disabled', false).html('<i class="fas fa-check mr-1"></i>Confirm'); $st.addClass('text-danger').text(errText(xhr) + (xhr.responseJSON && xhr.responseJSON.ref ? ' (ref ' + xhr.responseJSON.ref + ')' : '')); });
    });
    $no.on('click', function () { $c.fadeOut(150, function () { $c.remove(); }); });
    $('<div></div>').append($ok).append($no).appendTo($c); $st.appendTo($c);
    return $c;
  }

  function render(m) { bubble(m.role === 'user' ? 'user' : 'bot', m.content); (m.proposals || []).forEach(function (p) { $log.append(card(p, m.id)); }); scroll(); }

  function load() {
    loaded = true;
    $.getJSON($p.data('history')).done(function (r) {
      $('#aiMode').text(r.ai ? 'Gemini connected' : 'offline mode (no API key)');
      if (!r.messages.length) bubble('bot', 'Hi! I can find, create, update, complete and delete tasks for you. Anything that changes data appears as a card you confirm first.' + (r.ai ? '' : '\n\nI am in offline mode (no Gemini key), so I understand simple commands like “add task …”, “complete #3”, “show overdue”.'));
      r.messages.forEach(render);
      var $ch = $('#aiChips').empty(); (r.starters || []).forEach(function (s) { $('<button type="button"></button>').text(s).on('click', function () { send(s); }).appendTo($ch); });
    }).fail(function (xhr) { loaded = false; $('#aiMode').text('unavailable'); bubble('bot', 'I could not load the assistant: ' + errText(xhr)); });
  }

  function send(text) {
    text = $.trim(text || $in.val()); if (!text || busy) return;
    busy = true; $('#aiSend').prop('disabled', true); $in.val('').css('height', ''); $('#aiChips').hide();
    bubble('user', text); var $t = bubble('bot typing', 'Thinking…');
    $.ajax({ url: $p.data('chat'), method: 'POST', data: { message: text }, timeout: 90000 })
      .done(function (r) {
        $t.remove(); bubble('bot', r.reply);
        if (r.note) $('<div class="text-muted small px-1"></div>').text(r.note).appendTo($log);
        (r.proposals || []).forEach(function (p) { $log.append(card(p, r.message_id)); });
        scroll();
      })
      .fail(function (xhr, st) { $t.remove(); var m = st === 'timeout' ? 'That took too long. Please try again.' : errText(xhr); if (xhr.status === 422 && xhr.responseJSON && xhr.responseJSON.errors) m = Object.values(xhr.responseJSON.errors)[0][0]; bubble('bot', m + (xhr.responseJSON && xhr.responseJSON.ref ? ' (ref ' + xhr.responseJSON.ref + ')' : '')); })
      .always(function () { busy = false; $('#aiSend').prop('disabled', false); $in.trigger('focus'); });
  }

  $('#aiForm').on('submit', function (e) { e.preventDefault(); send(); });
  $in.on('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); } });
  $in.on('input', function () { this.style.height = 'auto'; this.style.height = Math.min(this.scrollHeight, 120) + 'px'; });
  $('#aiClear').on('click', function () { if (!confirm('Clear this conversation?')) return; $.ajax({ url: $p.data('clear'), method: 'DELETE' }).done(function () { $log.empty(); loaded = false; load(); }); });
})(jQuery);
