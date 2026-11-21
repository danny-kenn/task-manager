@extends('layouts.guest')
@section('title', 'Something went wrong')
@section('subtitle', 'Error 500')
@section('content')
<div class="text-center">
  <div class="mono" style="font-size:3rem;color:var(--gold)">500</div>
  <h5>Something went wrong</h5>
  <p class="text-muted">{{ $exception->getMessage() && ! in_array($exception->getMessage(), ['', 'Forbidden']) && 500 === 403 ? $exception->getMessage() : 'An unexpected error happened. It has been logged.' }}</p>
  <a href="{{ url('/') }}" class="btn btn-primary"><i class="fas fa-house mr-1"></i> Back to tasks</a>
</div>
@endsection
