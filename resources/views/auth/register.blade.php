@extends('layouts.guest')
@section('title', 'Create account')
@section('subtitle', 'Create your account')
@section('content')
<form method="POST" action="{{ route('register.store') }}" novalidate class="once" data-no-ajax>
  @csrf
  <div class="form-group">
    <label for="name">Full name</label>
    <input id="name" name="name" value="{{ old('name') }}" class="form-control @error('name') is-invalid @enderror" autofocus required>
    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
  </div>
  <div class="form-group">
    <label for="email">Email</label>
    <input type="email" id="email" name="email" value="{{ old('email') }}" class="form-control @error('email') is-invalid @enderror" required>
    @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
  </div>
  <div class="form-group">
    <label for="password">Password</label>
    <div class="pass-wrap"><input type="password" id="password" name="password" class="form-control @error('password') is-invalid @enderror" autocomplete="new-password" required><button type="button" data-toggle-pass="#password" aria-label="Show password"><i class="fas fa-eye"></i></button></div>
    <small class="form-text">At least 8 characters with letters and numbers.</small>
    @error('password')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
  </div>
  <div class="form-group">
    <label for="password_confirmation">Confirm password</label>
    <input type="password" id="password_confirmation" name="password_confirmation" class="form-control" autocomplete="new-password" required>
  </div>
  <button class="btn btn-primary btn-block" type="submit"><i class="fas fa-user-plus mr-1"></i> Create account</button>
</form>
<p class="text-center mt-3 mb-0" style="font-size:.88rem">Already registered? <a href="{{ route('login') }}">Sign in</a></p>
@endsection
