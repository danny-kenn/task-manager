@extends('layouts.guest')
@section('title', 'Sign in')
@section('subtitle', 'Sign in to manage your tasks')
@section('content')
<form method="POST" action="{{ route('login.attempt') }}" novalidate class="once" data-no-ajax>
  @csrf
  <input type="hidden" name="timezone" value="">
  <div class="form-group">
    <label for="email">Email</label>
    <input type="email" id="email" name="email" value="{{ old('email') }}" class="form-control @error('email') is-invalid @enderror" autocomplete="username" autofocus required>
    @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
  </div>
  <div class="form-group">
    <label for="password">Password</label>
    <div class="pass-wrap"><input type="password" id="password" name="password" class="form-control @error('password') is-invalid @enderror" autocomplete="current-password" required><button type="button" data-toggle-pass="#password" aria-label="Show password"><i class="fas fa-eye"></i></button></div>
    @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
  </div>
  <div class="custom-control custom-checkbox mb-3">
    <input type="checkbox" class="custom-control-input" id="remember" name="remember" value="1">
    <label class="custom-control-label" for="remember">Keep me signed in</label>
  </div>
  <button class="btn btn-primary btn-block" type="submit"><i class="fas fa-right-to-bracket mr-1"></i> Sign in</button>
</form>
<p class="text-center mt-3 mb-0" style="font-size:.88rem">New here? <a href="{{ route('register') }}">Create an account</a></p>

@if (app()->environment('local'))
<div class="demo-box mt-4">
  <strong>Demo accounts</strong> <span class="text-muted">(click to fill · password is “password”)</span>
  <div class="mt-1">
    <button type="button" data-fill-email="admin@taskmanager.test">Admin</button> ·
    <button type="button" data-fill-email="manager@taskmanager.test">Manager</button> ·
    <button type="button" data-fill-email="member@taskmanager.test">Member</button>
  </div>
</div>
@endif
@endsection
