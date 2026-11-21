@extends('layouts.guest')
@section('title', 'Access denied')
@section('subtitle', 'Error 403')
@section('content')
<div class="text-center">
  <div class="mono" style="font-size:3rem;color:var(--gold)">403</div>
  <h5>Access denied</h5>
  <p class="text-muted">{{ $exception->getMessage() && ! in_array($exception->getMessage(), ['', 'Forbidden']) && 403 === 403 ? $exception->getMessage() : 'Your role does not allow you to open this page.' }}</p>
  <a href="{{ url('/') }}" class="btn btn-primary"><i class="fas fa-house mr-1"></i> Back to tasks</a>
</div>
@endsection
