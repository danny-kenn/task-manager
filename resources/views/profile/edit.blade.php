@extends('layouts.app')
@section('title', 'My profile')
@section('heading', 'My profile')
@section('content')
<div class="page-head"><div><h2>My profile</h2><p>Update your details and password.</p></div></div>
<div class="row">
  <div class="col-lg-7">
    <div class="card mb-3"><div class="card-header">Details</div><div class="card-body">
      <form method="POST" action="{{ route('profile.update') }}" novalidate class="once">@csrf @method('PUT')
        <div class="form-group"><label for="name">Name</label><input id="name" name="name" value="{{ old('name', $user->name) }}" class="form-control @error('name') is-invalid @enderror">@error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="form-group"><label for="email">Email</label><input id="email" type="email" name="email" value="{{ old('email', $user->email) }}" class="form-control @error('email') is-invalid @enderror">@error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="form-group"><label>Avatar colour</label><div class="d-flex flex-wrap" style="gap:.5rem">
          @foreach (['#2c7a7b', '#d4a017', '#6f42c1', '#e83e8c', '#17a2b8', '#fd7e14', '#28a745', '#dc3545'] as $c)
            <label class="m-0"><input type="radio" name="avatar_color" value="{{ $c }}" class="sr-only" {{ old('avatar_color', $user->avatar_color) === $c ? 'checked' : '' }}><span class="avatar" style="background:{{ $c }};cursor:pointer;outline:{{ old('avatar_color', $user->avatar_color) === $c ? '3px solid var(--text)' : 'none' }};outline-offset:2px"></span></label>
          @endforeach</div>@error('avatar_color')<div class="text-danger small">{{ $message }}</div>@enderror</div>
        <button class="btn btn-primary"><i class="fas fa-floppy-disk mr-1"></i> Save</button>
      </form></div></div>

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
      <span class="avatar avatar-lg mb-2" style="background:{{ $user->avatar_color }}">{{ $user->initials }}</span>
      <h5 class="mb-1">{{ $user->name }}</h5><span class="badge badge-{{ $user->role->badge() }}">{{ $user->role->label() }}</span>
      <p class="text-muted mt-3 mb-0" style="font-size:.86rem">{{ $user->role->description() }}</p></div></div>
    <div class="card"><div class="card-body"><div class="row text-center">
      <div class="col"><div class="mono" style="font-size:1.4rem">{{ $stats['owned'] }}</div><small>Owned</small></div>
      <div class="col"><div class="mono" style="font-size:1.4rem">{{ $stats['assigned'] }}</div><small>Assigned</small></div>
      <div class="col"><div class="mono" style="font-size:1.4rem">{{ $stats['done'] }}</div><small>Done</small></div></div></div></div>
  </div>
</div>
@endsection
