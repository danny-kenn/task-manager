<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="vapid-key" content="{{ config('services.webpush.public_key') }}">
<meta name="theme-color" content="#0d1117" id="themeColor">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="{{ config('app.name') }}">
<title>@yield('title', 'Tasks') · {{ config('app.name') }}</title>
<link rel="manifest" href="{{ route('pwa.manifest') }}">
<link rel="icon" type="image/png" href="{{ asset('icons/favicon.png') }}">
<link rel="apple-touch-icon" href="{{ asset('icons/apple-touch-icon.png') }}">
<script>try{var t=localStorage.getItem('tm-theme')||'dark';document.documentElement.setAttribute('data-theme',t);document.getElementById('themeColor').setAttribute('content',t==='dark'?'#0d1117':'#ffffff')}catch(e){}</script>
<link rel="stylesheet" href="{{ asset('vendor/bootstrap/bootstrap.min.css') }}">
<link rel="stylesheet" href="{{ asset('vendor/fontawesome/css/all.min.css') }}">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Sora:wght@500;600;700;800&family=Roboto+Mono:wght@500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
@if (file_exists(public_path('css/ai.css')))<link rel="stylesheet" href="{{ asset('css/ai.css') }}?v={{ filemtime(public_path('css/ai.css')) }}">@endif
@php($u = auth()->user())
@stack('head')
<script>
window.TMCFG = {
  uid: {{ $u->id }},
  tour: {{ $u->onboarded_at ? 'false' : 'true' }}, role: @json($u->role->value), name: @json($u->first_name),
  tourDone: @json(route('tour.done')), tourReset: @json(route('tour.reset')),
  routes: {
    poll: @json(route('notifications.poll')),
    read: @json(route('notifications.read', ['id' => '__ID__'])),
    readAll: @json(route('notifications.read_all')),
    subscribe: @json(route('push.subscribe')),
    unsubscribe: @json(route('push.unsubscribe')),
    avatar: @json(route('profile.avatar')),
    avatarRemove: @json(route('profile.avatar.remove')),
    offline: @json(route('pwa.offline')),
    login: @json(route('login'))
  },
  vapid: @json((string) config('services.webpush.public_key'))
};
</script>
<script src="{{ asset('vendor/jquery/jquery.min.js') }}"></script>
<script src="{{ asset('vendor/bootstrap/bootstrap.bundle.min.js') }}"></script>
<script src="{{ asset('vendor/sortable/Sortable.min.js') }}"></script>
<link rel="stylesheet" href="{{ asset('css/tour.css') }}?v={{ filemtime(public_path('css/tour.css')) }}">
</head>
<body class="has-bottom-nav">
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false"><defs><mask id="tmLogoMask" maskUnits="userSpaceOnUse" x="0" y="0" width="32" height="32"><rect width="32" height="32" fill="#fff"/><path d="M9.4 16.4l3 3 5.8-5.8" fill="none" stroke="#000" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/><circle cx="25" cy="7" r="6.6" fill="#000"/></mask></defs><symbol id="tm-logo" viewBox="0 0 32 32"><path mask="url(#tmLogoMask)" fill="currentColor" d="M8 7h12a6 6 0 0 1 6 6v6a6 6 0 0 1-6 6h-7.5L6.5 29.5V24.6A6 6 0 0 1 2 19v-6a6 6 0 0 1 6-6z"/><path fill="#f2c94c" d="M25 1.8l1.4 3.8L30.2 7l-3.8 1.4L25 12.2l-1.4-3.8L19.8 7l3.8-1.4z"/></symbol></svg>
<div class="progress-line" id="progressLine"></div>
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

{{-- ============================================================ desktop sidebar --}}
<aside class="sidebar" id="sidebar">
  <div class="sidebar-brand">
    <div class="brand-title"><span class="brand-mark"><svg viewBox="0 0 32 32" width="22" height="22" aria-hidden="true" style="color:#fff"><use href="#tm-logo"/></svg></span>{{ config('app.name') }}</div>
    <a href="{{ route('profile.edit') }}" class="brand-me">
      <x-avatar :user="$u" size="md" data-self />
      <span class="brand-me-text"><span class="brand-user">{{ $u->name }}</span><span class="role-pill">{{ $u->role->label() }}</span></span>
    </a>
  </div>
  <nav class="sidebar-scroll">
    <div class="nav-label">Workspace</div>
    <a class="s-link" data-nav-routes="dashboard" href="{{ route('dashboard') }}"><i class="fas fa-chart-pie"></i> Dashboard</a>
    <a class="s-link" data-nav-routes="tasks.index,tasks.show,tasks.edit,tasks.create" data-nav-not="assignee=me" href="{{ route('tasks.index') }}"><i class="fas fa-list-check"></i> Tasks</a>
    <a class="s-link" data-nav-routes="tasks.index" data-nav-query="assignee=me" href="{{ route('tasks.index', ['assignee' => 'me', 'status' => 'pending']) }}"><i class="fas fa-user-check"></i> Assigned to me</a>
    @can('view-trash')
      <a class="s-link" data-nav-routes="tasks.trash" href="{{ route('tasks.trash') }}"><i class="fas fa-trash-can"></i> Trash</a>
    @endcan
    @canany(['manage-categories', 'view-activity'])
      <div class="nav-label">Management</div>
      @can('manage-categories')<a class="s-link" data-nav-routes="categories.index" href="{{ route('categories.index') }}"><i class="fas fa-tags"></i> Categories</a>@endcan
      @can('view-activity')<a class="s-link" data-nav-routes="activity.index" href="{{ route('activity.index') }}"><i class="fas fa-clock-rotate-left"></i> Activity log</a>@endcan
    @endcanany
    @can('manage-users')
      <div class="nav-label">Administration</div>
      <a class="s-link" data-nav-routes="users.index,users.create,users.edit" href="{{ route('users.index') }}"><i class="fas fa-users-gear"></i> Users &amp; roles</a>
    @endcan
    <div class="nav-label">Account</div>
    <a class="s-link" data-nav-routes="profile.edit" href="{{ route('profile.edit') }}"><i class="fas fa-user-gear"></i> My profile</a>
    <a class="s-link" href="#" data-notif-open><i class="fas fa-bell"></i> Notifications <span class="badge badge-pill badge-gold ml-auto d-none" data-unread-badge></span></a>
    @can('use-ai')<a class="s-link" href="#" data-ai-open id="openAiSide"><svg class="tm-ai-logo" viewBox="0 0 32 32" width="1em" height="1em" aria-hidden="true"><use href="#tm-logo"/></svg> AI assistant</a>@endcan
    <a class="s-link d-none" href="#" data-install><i class="fas fa-download"></i> Install app</a>
  </nav>
  <div class="sidebar-foot">Plan it · Do it · Ask AI</div>
</aside>

<div class="main">
  {{-- ============================================================ top app bar --}}
  <header class="topbar">
    <div class="topbar-l">
      <button class="icon-btn back-btn d-none" id="backBtn" type="button" aria-label="Back"><i class="fas fa-chevron-left"></i></button>
      <h1 id="pageTitle">@yield('heading', 'Tasks')</h1>
    </div>
    <div class="topbar-r">
      <a href="{{ route('tasks.create') }}" class="btn btn-primary btn-sm new-task-btn"><i class="fas fa-plus mr-1"></i><span>New task</span></a>
      <button class="icon-btn d-none d-lg-inline-flex" id="themeBtn" type="button" aria-label="Toggle theme" title="Light / dark"><i class="fas fa-sun"></i></button>
      <button class="icon-btn bell-btn" id="bellBtn" type="button" aria-label="Notifications" aria-expanded="false"><i class="fas fa-bell"></i><span class="bell-badge d-none" data-unread-badge></span></button>
      <div class="dropdown d-none d-lg-block">
        <button class="btn p-0 border-0 bg-transparent" data-toggle="dropdown" aria-label="Account"><x-avatar :user="$u" size="md" data-self /></button>
        <div class="dropdown-menu dropdown-menu-right">
          <div class="px-3 py-2"><strong>{{ $u->name }}</strong><br><small>{{ $u->email }}</small></div>
          <div class="dropdown-divider"></div>
          <a class="dropdown-item" href="{{ route('profile.edit') }}"><i class="fas fa-user-gear mr-2"></i>Profile</a>
          <form method="POST" action="{{ route('logout') }}" data-no-ajax>@csrf<button class="dropdown-item" type="submit"><i class="fas fa-right-from-bracket mr-2"></i>Sign out</button></form>
        </div>
      </div>
      <a class="d-lg-none topbar-avatar" href="{{ route('profile.edit') }}" aria-label="My profile"><x-avatar :user="$u" size="md" data-self /></a>
    </div>
  </header>

  <main class="content">
    {{-- Everything inside #pjax is swapped on navigation; the shell around it (sidebar, bars, AI panel) never reloads. --}}
    <div id="pjax" data-heading="@yield('heading', 'Tasks')" data-route="{{ Route::currentRouteName() }}" data-back="{{ Route::is('tasks.show', 'tasks.edit', 'tasks.create', 'users.create', 'users.edit') ? 1 : 0 }}">
      @include('partials.flash')
      @yield('content')
      @stack('scripts')
    </div>
  </main>
</div>

{{-- ============================================================ mobile bottom navigation --}}
<nav class="bottom-nav" id="bottomNav" aria-label="Main">
  <a class="bn-item" data-nav-routes="dashboard" href="{{ route('dashboard') }}"><i class="fas fa-chart-pie"></i><span>Home</span></a>
  <a class="bn-item" data-nav-routes="tasks.index,tasks.show,tasks.edit,tasks.create" data-nav-not="assignee=me" href="{{ route('tasks.index') }}"><i class="fas fa-list-check"></i><span>Tasks</span></a>
  @can('use-ai')
    <button class="bn-ai" type="button" data-ai-open aria-label="AI assistant"><svg class="tm-ai-logo" viewBox="0 0 32 32" width="1em" height="1em" aria-hidden="true"><use href="#tm-logo"/></svg></button>
  @else
    <a class="bn-ai" href="{{ route('tasks.create') }}" aria-label="New task"><i class="fas fa-plus"></i></a>
  @endcan
  <a class="bn-item" data-nav-routes="tasks.index" data-nav-query="assignee=me" href="{{ route('tasks.index', ['assignee' => 'me', 'status' => 'pending']) }}"><i class="fas fa-user-check"></i><span>Mine</span></a>
  <button class="bn-item" type="button" id="moreBtn"><i class="fas fa-grip"></i><span>More</span></button>
</nav>

{{-- ============================================================ bottom sheets (mobile) / dropdown (notifications on desktop) --}}
<div class="sheet-backdrop" id="sheetBackdrop"></div>

<section class="sheet" id="moreSheet" aria-label="More" aria-hidden="true">
  <div class="sheet-grip"></div>
  <div class="sheet-user">
    <x-avatar :user="$u" size="lg" data-self />
    <div><strong>{{ $u->name }}</strong><br><span class="role-pill">{{ $u->role->label() }}</span></div>
    <button class="icon-btn ml-auto" type="button" data-sheet-close aria-label="Close"><i class="fas fa-xmark"></i></button>
  </div>
  <div class="tile-grid">
    @can('view-trash')<a class="tile" href="{{ route('tasks.trash') }}"><i class="fas fa-trash-can"></i><span>Trash</span></a>@endcan
    @can('manage-categories')<a class="tile" href="{{ route('categories.index') }}"><i class="fas fa-tags"></i><span>Categories</span></a>@endcan
    @can('view-activity')<a class="tile" href="{{ route('activity.index') }}"><i class="fas fa-clock-rotate-left"></i><span>Activity</span></a>@endcan
    @can('manage-users')<a class="tile" href="{{ route('users.index') }}"><i class="fas fa-users-gear"></i><span>Users &amp; roles</span></a>@endcan
    <a class="tile" href="{{ route('profile.edit') }}"><i class="fas fa-user-gear"></i><span>My profile</span></a>
    <button class="tile" type="button" data-notif-open><i class="fas fa-bell"></i><span>Notifications</span><b class="tile-badge d-none" data-unread-badge></b></button>
    <button class="tile" type="button" data-theme-toggle><i class="fas fa-circle-half-stroke"></i><span>Theme</span></button>
    <button class="tile d-none" type="button" data-install><i class="fas fa-download"></i><span>Install app</span></button>
    <form method="POST" action="{{ route('logout') }}" data-no-ajax class="tile-form">@csrf<button class="tile tile-danger" type="submit"><i class="fas fa-right-from-bracket"></i><span>Sign out</span></button></form>
  </div>
</section>

<section class="notif-panel" id="notifPanel" aria-label="Notifications" aria-hidden="true">
  <div class="sheet-grip d-lg-none"></div>
  <div class="notif-head">
    <strong>Notifications</strong>
    <button class="btn btn-link btn-sm" type="button" id="notifReadAll">Mark all read</button>
    <button class="icon-btn" type="button" data-sheet-close aria-label="Close"><i class="fas fa-xmark"></i></button>
  </div>
  <div class="notif-list" id="notifList"><div class="notif-empty"><i class="far fa-bell-slash"></i><p>You are all caught up.</p></div></div>
  <a class="notif-foot" href="{{ route('profile.edit') }}#notifications"><i class="fas fa-sliders mr-1"></i>Notification settings</a>
</section>

@include('partials.delete-modal')
<div id="toasts" aria-live="polite"></div>
@can('use-ai') @include('partials.ai') @endcan

<script src="{{ asset('js/tour.js') }}?v={{ filemtime(public_path('js/tour.js')) }}"></script>
<script src="{{ asset('js/app.js') }}?v={{ filemtime(public_path('js/app.js')) }}"></script>
</body>
</html>
