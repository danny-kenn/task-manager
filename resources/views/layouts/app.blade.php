<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('title', 'Tasks') · {{ config('app.name') }}</title>
<script>try{document.documentElement.setAttribute('data-theme',localStorage.getItem('tm-theme')||'dark')}catch(e){}</script>
<link rel="stylesheet" href="{{ asset('vendor/bootstrap/bootstrap.min.css') }}">
<link rel="stylesheet" href="{{ asset('vendor/fontawesome/css/all.min.css') }}">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Roboto+Mono:wght@500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
@stack('head')
</head>
<body>
@php($u = auth()->user())
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>
<aside class="sidebar" id="sidebar">
  <div class="sidebar-brand">
    <div class="brand-title"><i class="fas fa-layer-group"></i>{{ config('app.name') }}</div>
    <div class="brand-user">{{ $u->name }}</div>
    <span class="role-pill">{{ $u->role->label() }}</span>
  </div>
  <nav class="sidebar-scroll">
    <div class="nav-label">Workspace</div>
    <a class="s-link {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}"><i class="fas fa-chart-pie"></i> Dashboard</a>
    <a class="s-link {{ request()->routeIs('tasks.index', 'tasks.show', 'tasks.edit', 'tasks.create') ? 'active' : '' }}" href="{{ route('tasks.index') }}"><i class="fas fa-list-check"></i> Tasks</a>
    <a class="s-link" href="{{ route('tasks.index', ['assignee' => 'me', 'status' => 'pending']) }}"><i class="fas fa-user-check"></i> Assigned to me</a>
    @can('view-trash')
      <a class="s-link {{ request()->routeIs('tasks.trash') ? 'active' : '' }}" href="{{ route('tasks.trash') }}"><i class="fas fa-trash-can"></i> Trash</a>
    @endcan
    @canany(['manage-categories', 'view-activity'])
      <div class="nav-label">Management</div>
      @can('manage-categories')<a class="s-link {{ request()->routeIs('categories.*') ? 'active' : '' }}" href="{{ route('categories.index') }}"><i class="fas fa-tags"></i> Categories</a>@endcan
      @can('view-activity')<a class="s-link {{ request()->routeIs('activity.*') ? 'active' : '' }}" href="{{ route('activity.index') }}"><i class="fas fa-clock-rotate-left"></i> Activity log</a>@endcan
    @endcanany
    @can('manage-users')
      <div class="nav-label">Administration</div>
      <a class="s-link {{ request()->routeIs('users.*') ? 'active' : '' }}" href="{{ route('users.index') }}"><i class="fas fa-users-gear"></i> Users &amp; roles</a>
    @endcan
    <div class="nav-label">Account</div>
    <a class="s-link {{ request()->routeIs('profile.*') ? 'active' : '' }}" href="{{ route('profile.edit') }}"><i class="fas fa-user-gear"></i> My profile</a>
    <a class="s-link" href="#" id="openAiSide"><i class="fas fa-wand-magic-sparkles"></i> AI assistant</a>
  </nav>
  <div class="sidebar-foot">Laravel {{ app()->version() }} · Bootstrap 4.6</div>
</aside>

<div class="main">
  <header class="topbar">
    <div class="d-flex align-items-center" style="gap:.75rem">
      <button class="icon-btn hamburger" id="menuBtn" aria-label="Menu"><i class="fas fa-bars"></i></button>
      <h1>@yield('heading', 'Tasks')</h1>
    </div>
    <div class="d-flex align-items-center" style="gap:.6rem">
      <a href="{{ route('tasks.create') }}" class="btn btn-primary btn-sm d-none d-sm-inline-block"><i class="fas fa-plus mr-1"></i> New task</a>
      <button class="icon-btn" id="themeBtn" aria-label="Toggle theme" title="Light / dark"><i class="fas fa-sun"></i></button>
      <div class="dropdown">
        <button class="btn p-0 border-0 bg-transparent" data-toggle="dropdown" aria-label="Account"><span class="avatar" style="background:{{ $u->avatar_color }}">{{ $u->initials }}</span></button>
        <div class="dropdown-menu dropdown-menu-right">
          <div class="px-3 py-2"><strong>{{ $u->name }}</strong><br><small>{{ $u->email }}</small></div>
          <div class="dropdown-divider"></div>
          <a class="dropdown-item" href="{{ route('profile.edit') }}"><i class="fas fa-user-gear mr-2"></i>Profile</a>
          <form method="POST" action="{{ route('logout') }}">@csrf<button class="dropdown-item" type="submit"><i class="fas fa-right-from-bracket mr-2"></i>Sign out</button></form>
        </div>
      </div>
    </div>
  </header>

  <main class="content">
    @include('partials.flash')
    @yield('content')
  </main>
</div>

@include('partials.delete-modal')
<div id="toasts" aria-live="polite"></div>
@can('use-ai') @include('partials.ai') @endcan

<script src="{{ asset('vendor/jquery/jquery.min.js') }}"></script>
<script src="{{ asset('vendor/bootstrap/bootstrap.bundle.min.js') }}"></script>
<script src="{{ asset('vendor/sortable/Sortable.min.js') }}"></script>
<script src="{{ asset('js/app.js') }}?v={{ filemtime(public_path('js/app.js')) }}"></script>
@stack('scripts')
</body>
</html>
