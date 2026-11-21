@extends('layouts.guest')
@section('title', 'Session expired')
@section('subtitle', 'Error 419')
@section('content')
<div class="text-center">
  <div class="mono" style="font-size:3rem;color:var(--gold)">419</div>
  <h5>Session expired</h5>
  <p class="text-muted">{{ $exception->getMessage() && ! in_array($exception->getMessage(), ['', 'Forbidden']) && 419 === 403 ? $exception->getMessage() : 'Please refresh the page and try again.' }}</p>
  <a href="{{ url('/') }}" class="btn btn-primary"><i class="fas fa-house mr-1"></i> Back to tasks</a>
</div>
@endsection
