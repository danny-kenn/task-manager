@extends('layouts.guest')
@section('title', 'Slow down')
@section('subtitle', 'Error 429')
@section('content')
<div class="text-center">
  <div class="mono" style="font-size:3rem;color:var(--gold)">429</div>
  <h5>Slow down</h5>
  <p class="text-muted">{{ $exception->getMessage() && ! in_array($exception->getMessage(), ['', 'Forbidden']) && 429 === 403 ? $exception->getMessage() : 'Too many requests. Try again in a moment.' }}</p>
  <a href="{{ url('/') }}" class="btn btn-primary"><i class="fas fa-house mr-1"></i> Back to tasks</a>
</div>
@endsection
