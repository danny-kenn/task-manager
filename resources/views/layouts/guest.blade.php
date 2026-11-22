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
<div class="auth-wrap">
  <div class="card auth-card">
    <div class="text-center mb-4">
      <div class="auth-logo"><i class="fas fa-layer-group"></i></div>
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
<script src="{{ asset('js/app.js') }}?v={{ filemtime(public_path('js/app.js')) }}"></script>
</body>
</html>
