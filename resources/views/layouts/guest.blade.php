<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="theme-color" content="#0d1117">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<title>@yield('title') · {{ config('app.name') }}</title>
<link rel="manifest" href="{{ route('pwa.manifest') }}">
<link rel="icon" type="image/png" href="{{ asset('icons/favicon.png') }}">
<link rel="apple-touch-icon" href="{{ asset('icons/apple-touch-icon.png') }}">
<script>try{document.documentElement.setAttribute('data-theme',localStorage.getItem('tm-theme')||'dark')}catch(e){}</script>
<link rel="stylesheet" href="{{ asset('vendor/bootstrap/bootstrap.min.css') }}">
<link rel="stylesheet" href="{{ asset('vendor/fontawesome/css/all.min.css') }}">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Roboto+Mono:wght@500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
</head>
<body class="guest-body">
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false"><defs><mask id="tmLogoMask" maskUnits="userSpaceOnUse" x="0" y="0" width="32" height="32"><rect width="32" height="32" fill="#fff"/><path d="M9.4 16.4l3 3 5.8-5.8" fill="none" stroke="#000" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/><circle cx="25" cy="7" r="6.6" fill="#000"/></mask></defs><symbol id="tm-logo" viewBox="0 0 32 32"><path mask="url(#tmLogoMask)" fill="currentColor" d="M8 7h12a6 6 0 0 1 6 6v6a6 6 0 0 1-6 6h-7.5L6.5 29.5V24.6A6 6 0 0 1 2 19v-6a6 6 0 0 1 6-6z"/><path fill="#f2c94c" d="M25 1.8l1.4 3.8L30.2 7l-3.8 1.4L25 12.2l-1.4-3.8L19.8 7l3.8-1.4z"/></symbol></svg>
<div class="auth-wrap">
  <div class="card auth-card">
    <div class="text-center mb-4">
      <div class="auth-logo"><svg viewBox="0 0 32 32" aria-hidden="true"><use href="#tm-logo"/></svg></div>
      <div class="brand-title" style="font-size:1.15rem">{{ config('app.name') }}</div>
      <p class="text-muted mb-0 mt-1" style="font-size:.88rem">@yield('subtitle')</p>
    </div>
    @include('partials.flash')
    @yield('content')
  </div>
</div>
<div id="toasts"></div>
<script src="{{ asset('vendor/jquery/jquery.min.js') }}"></script>
<script src="{{ asset('vendor/bootstrap/bootstrap.bundle.min.js') }}"></script>
<script>try{var z=Intl.DateTimeFormat().resolvedOptions().timeZone;document.querySelectorAll('input[name=timezone]').forEach(function(i){i.value=z||''})}catch(e){}</script>
<script src="{{ asset('js/app.js') }}?v={{ filemtime(public_path('js/app.js')) }}"></script>
</body>
</html>
