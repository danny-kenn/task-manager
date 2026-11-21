@extends('layouts.app')
@php($isEdit = $user->exists)
@section('title', $isEdit ? 'Edit user' : 'New user')
@section('heading', 'Users & roles')
@section('content')
<div class="page-head"><div><nav class="breadcrumb"><a href="{{ route('users.index') }}">Users</a><span class="mx-2">/</span>{{ $isEdit ? 'Edit' : 'New' }}</nav><h2>{{ $isEdit ? 'Edit '.$user->name : 'Create a user' }}</h2></div></div>
<div class="card" style="max-width:640px"><div class="card-body">
<form method="POST" action="{{ $isEdit ? route('users.update', $user) : route('users.store') }}" novalidate class="once">
  @csrf @if ($isEdit) @method('PUT') @endif
  @include('partials.errors')
  <div class="form-group"><label for="name">Name</label><input id="name" name="name" value="{{ old('name', $user->name) }}" class="form-control @error('name') is-invalid @enderror"></div>
  <div class="form-group"><label for="email">Email</label><input id="email" type="email" name="email" value="{{ old('email', $user->email) }}" class="form-control @error('email') is-invalid @enderror"></div>
  <div class="form-group"><label for="password">Password {{ $isEdit ? '(leave blank to keep)' : '' }}</label><input id="password" type="password" name="password" class="form-control @error('password') is-invalid @enderror" autocomplete="new-password"></div>
  <div class="form-group"><label for="role">Role</label>
    <select id="role" name="role" class="custom-select" {{ $isEdit && $user->id === auth()->id() ? 'disabled' : '' }}>@foreach (\App\Enums\UserRole::cases() as $r)<option value="{{ $r->value }}" @selected(old('role', $user->role?->value) === $r->value)>{{ $r->label() }} — {{ $r->description() }}</option>@endforeach</select>
    @if ($isEdit && $user->id === auth()->id())<small class="form-text">You cannot change your own role.</small>@endif</div>
  <div class="custom-control custom-switch mb-3"><input type="checkbox" class="custom-control-input" id="is_active" name="is_active" value="1" {{ old('is_active', $user->is_active ?? true) ? 'checked' : '' }} {{ $isEdit && $user->id === auth()->id() ? 'disabled' : '' }}><label class="custom-control-label" for="is_active">Account is active</label></div>
  <button class="btn btn-primary" type="submit"><i class="fas fa-floppy-disk mr-1"></i> {{ $isEdit ? 'Save changes' : 'Create user' }}</button> <a href="{{ route('users.index') }}" class="btn btn-light">Cancel</a>
</form></div></div>
@endsection
