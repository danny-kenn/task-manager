@extends('layouts.app')
@section('title', 'My profile')
@section('heading', 'My profile')
@section('content')
<div class="page-head"><div><h2>My profile</h2><p>Your photo, details, notifications and password.</p></div><button type="button" class="btn btn-outline-secondary" id="replayTour"><i class="fas fa-route mr-1"></i> Replay the welcome tour</button></div>
<div class="row">
  <div class="col-lg-7">
    <div class="card mb-3"><div class="card-header">Photo &amp; details</div><div class="card-body">
      <div class="avatar-uploader mb-4">
        <x-avatar :user="$user" size="xl" id="avatarPreview" />
        <div class="meta">
          <input type="file" id="avatarFile" accept="image/*" hidden>
          <button type="button" class="btn btn-primary btn-sm" id="avatarPick"><i class="fas fa-camera mr-1"></i> Upload photo</button>
          <button type="button" class="btn btn-light btn-sm {{ $user->avatar_url ? '' : 'd-none' }}" id="avatarRemove"><i class="fas fa-trash mr-1"></i> Remove</button>
          <small class="mt-2">Shown on your avatar, on tasks and in comments. It is cropped to a square automatically.</small>
          <div class="progress mt-2 d-none" id="avatarProg" style="height:6px;width:200px"><div class="progress-bar" style="width:0"></div></div>
        </div>
      </div>
      <form method="POST" action="{{ route('profile.update') }}" novalidate class="once">@csrf @method('PUT')
        <div class="form-group"><label for="name">Name</label><input id="name" name="name" value="{{ old('name', $user->name) }}" class="form-control @error('name') is-invalid @enderror">@error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="form-group"><label for="email">Email</label><input id="email" type="email" name="email" value="{{ old('email', $user->email) }}" class="form-control @error('email') is-invalid @enderror">@error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="form-group"><label>Avatar colour <small>(used when you have no photo)</small></label><div class="d-flex flex-wrap" style="gap:.5rem">
          @foreach (['#2c7a7b', '#d4a017', '#6f42c1', '#e83e8c', '#17a2b8', '#fd7e14', '#28a745', '#dc3545'] as $c)
            <label class="m-0"><input type="radio" name="avatar_color" value="{{ $c }}" class="sr-only" {{ old('avatar_color', $user->avatar_color) === $c ? 'checked' : '' }}><span class="avatar" style="background:{{ $c }};cursor:pointer;outline:{{ old('avatar_color', $user->avatar_color) === $c ? '3px solid var(--text)' : 'none' }};outline-offset:2px"></span></label>
          @endforeach</div>@error('avatar_color')<div class="text-danger small">{{ $message }}</div>@enderror</div>
        <button class="btn btn-primary"><i class="fas fa-floppy-disk mr-1"></i> Save</button>
      </form></div></div>

    <div class="card mb-3" id="notifications"><div class="card-header">Notifications</div><div class="card-body">
      <p class="text-muted" style="font-size:.88rem">Choose what reaches you, and where. The bell inside the app is always the fastest.</p>
      <form method="POST" action="{{ route('profile.notifications') }}" id="prefsForm">@csrf @method('PUT')
        <table class="table prefs-table mb-3"><thead><tr><th>When…</th>@foreach ($channels as $label)<th>{{ $label }}</th>@endforeach</tr></thead><tbody>
          @foreach ($events as $key => $e)
            <tr><td><i class="fas {{ $e['icon'] }} mr-2 text-muted"></i>{{ $e['label'] }}</td>
              @foreach ($channels as $ch => $label)<td data-ch="{{ $label }}"><input type="checkbox" class="pref-check" name="prefs[{{ $key }}][{{ $ch }}]" value="1" @checked($user->wantsNotification($key, $ch))></td>@endforeach</tr>
          @endforeach
        </tbody></table>
        <button class="btn btn-primary"><i class="fas fa-floppy-disk mr-1"></i> Save notification settings</button>
      </form>
      <hr>
      <div class="d-flex align-items-center flex-wrap" style="gap:.8rem">
        <div class="flex-fill"><strong>Push notifications on this device</strong><br><small id="pushState" class="text-muted">{{ $pushReady ? 'Checking…' : 'Not configured on the server yet (run php artisan webpush:keys).' }}</small></div>
        <button class="btn btn-light" type="button" id="pushToggle" disabled><i class="fas fa-bell mr-1"></i> Enable</button>
        <button class="btn btn-light d-none" type="button" data-install><i class="fas fa-download mr-1"></i> Install app</button>
      </div>
    </div></div>

    <div class="card mb-3"><div class="card-header">Change password</div><div class="card-body">
      <form method="POST" action="{{ route('profile.password') }}" novalidate class="once">@csrf @method('PUT')
        <div class="form-group"><label>Current password</label><input type="password" name="current_password" class="form-control @error('current_password') is-invalid @enderror" autocomplete="current-password">@error('current_password')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="form-group"><label>New password</label><input type="password" name="password" class="form-control @error('password') is-invalid @enderror" autocomplete="new-password">@error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="form-group"><label>Confirm new password</label><input type="password" name="password_confirmation" class="form-control" autocomplete="new-password"></div>
        <button class="btn btn-primary"><i class="fas fa-key mr-1"></i> Update password</button>
      </form></div></div>
  </div>
  <div class="col-lg-5">
    <div class="card mb-3"><div class="card-body text-center">
      <x-avatar :user="$user" size="lg" data-self class="mb-2" />
      <h5 class="mb-1">{{ $user->name }}</h5><span class="badge badge-{{ $user->role->badge() }}">{{ $user->role->label() }}</span>
      <p class="text-muted mt-3 mb-0" style="font-size:.86rem">{{ $user->role->description() }}</p></div></div>
    <div class="card"><div class="card-body"><div class="row text-center">
      <div class="col"><div class="mono" style="font-size:1.4rem">{{ $stats['owned'] }}</div><small>Owned</small></div>
      <div class="col"><div class="mono" style="font-size:1.4rem">{{ $stats['assigned'] }}</div><small>Assigned</small></div>
      <div class="col"><div class="mono" style="font-size:1.4rem">{{ $stats['done'] }}</div><small>Done</small></div></div></div></div>
  </div>
</div>

@push('scripts')
<script>
(function ($) {
  // ---- avatar: crop to a square and shrink to 512px in the browser first, so the upload is tiny and fast
  var $file = $('#avatarFile'), $prog = $('#avatarProg');
  $('#avatarPick').on('click', function () { $file.trigger('click'); });
  $file.on('change', function () {
    var f = this.files[0]; if (!f) return; this.value = '';
    var img = new Image(), url = URL.createObjectURL(f);
    img.onload = function () {
      var s = Math.min(img.width, img.height), c = document.createElement('canvas'); c.width = c.height = 512;
      c.getContext('2d').drawImage(img, (img.width - s) / 2, (img.height - s) / 2, s, s, 0, 0, 512, 512); URL.revokeObjectURL(url);
      c.toBlob(function (blob) {
        var fd = new FormData(); fd.append('avatar', blob, 'avatar.jpg'); $prog.removeClass('d-none').find('.progress-bar').css('width', '30%');
        $.ajax({ url: TMCFG.routes.avatar, method: 'POST', data: fd, processData: false, contentType: false,
          xhr: function () { var x = $.ajaxSettings.xhr(); if (x.upload) x.upload.onprogress = function (e) { if (e.lengthComputable) $prog.find('.progress-bar').css('width', (e.loaded / e.total * 100) + '%'); }; return x; } })
          .done(function (r) { toast(r.message, 'ok'); TM.setAvatar(r.url); setPreview(r.url); $('#avatarRemove').removeClass('d-none'); })
          .fail(function (x) { toast(errText(x), 'err'); }).always(function () { setTimeout(function () { $prog.addClass('d-none'); }, 400); });
      }, 'image/jpeg', 0.88);
    };
    img.onerror = function () { toast('That file is not a picture.', 'err'); }; img.src = url;
  });
  function setPreview(u) { var $p = $('#avatarPreview'); $p.find('img').remove(); if (u) { $p.contents().filter(function () { return this.nodeType === 3; }).remove(); $('<img alt="">').attr('src', u).appendTo($p); } else $p.text($p.data('initials')); }
  // avatar colour: show the chosen swatch + preview instantly (saved when you press Save)
  $('input[name=avatar_color]').on('change', function () {
    var c = this.value;
    $('input[name=avatar_color]').each(function () { $(this).next('.avatar').css('outline', this.checked ? '3px solid var(--text)' : 'none'); });
    $('#avatarPreview, .avatar[data-self]').each(function () { if (!$(this).find('img').length) $(this).css('background', c); });
  });
  $('#avatarRemove').on('click', function () { var $b = $(this); $.ajax({ url: TMCFG.routes.avatarRemove, method: 'DELETE' }).done(function (r) { toast(r.message, 'ok'); TM.setAvatar(null); setPreview(null); $b.addClass('d-none'); }).fail(function (x) { toast(errText(x), 'err'); }); });

  // ---- notification settings save without leaving the page
  $('#prefsForm').on('submit', function (e) { e.preventDefault(); var $f = $(this); $.ajax({ url: $f.attr('action'), method: 'POST', data: $f.serialize() }).done(function (r) { toast(r.message, 'ok'); }).fail(function (x) { toast(errText(x), 'err'); }); });

  // ---- push toggle
  var $t = $('#pushToggle'), $s = $('#pushState');
  function paint(on) { $t.prop('disabled', false).html(on ? '<i class="fas fa-bell-slash mr-1"></i> Turn off' : '<i class="fas fa-bell mr-1"></i> Enable'); $s.text(on ? 'On for this device. You will get alerts even when the app is closed.' : 'Off on this device.'); $t.data('on', on); }
  if (window.TMPush && TMPush.supported) { TMPush.enabled().then(paint); } else if ($t.length) { $t.prop('disabled', true); $s.text(TMCFG.vapid ? 'This browser does not support push notifications.' : 'Not configured on the server yet (run php artisan webpush:keys).'); }
  $t.on('click', function () { $t.prop('disabled', true); (($t.data('on') ? TMPush.disable() : TMPush.enable())).then(function () { return TMPush.enabled(); }).then(function (on) { paint(on); toast(on ? 'Push notifications enabled.' : 'Push notifications turned off.', 'ok'); }).catch(function (e) { toast(e.message || 'Could not change push settings.', 'err'); TMPush.enabled().then(paint); }); });
})(jQuery);
</script>
@endpush
@endsection
