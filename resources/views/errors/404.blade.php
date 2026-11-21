@extends('layouts.guest')
@section('title', 'Page not found')
@section('subtitle', 'Error 404')
@section('content')
<div class="text-center">
  <div class="mono" style="font-size:3rem;color:var(--gold)">404</div>
  <h5>Page not found</h5>
  <p class="text-muted">{{ $exception->getMessage() && ! in_array($exception->getMessage(), ['', 'Forbidden']) && 404 === 403 ? $exception->getMessage() : 'We could not find what you were looking for.' }}</p>
  <a href="{{ url('/') }}" class="btn btn-primary"><i class="fas fa-house mr-1"></i> Back to tasks</a>
</div>
@endsection
