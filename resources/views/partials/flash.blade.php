{{-- JS turns these into toasts and removes them; <noscript> keeps them visible without JavaScript. --}}
@foreach (['success', 'error'] as $kind)
  @if (session($kind))
    <div data-flash="{{ $kind }}" data-flash-msg="{{ session($kind) }}" hidden></div>
    <noscript><div class="alert alert-{{ $kind === 'error' ? 'danger' : 'success' }}" role="alert">{{ session($kind) }}</div></noscript>
  @endif
@endforeach
