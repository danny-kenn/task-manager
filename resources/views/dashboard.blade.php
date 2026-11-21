@extends('layouts.app')
@section('title', 'Dashboard')
@section('heading', 'Dashboard')
@section('content')
<div class="page-head">
  <div>
    <h2>Hello, {{ explode(' ', auth()->user()->name)[0] }}</h2>
    <p>{{ $s['pending'] }} pending · {{ $s['overdue'] }} overdue · {{ $s['due_today'] }} due today</p>
  </div>
  <a href="{{ route('tasks.create') }}" class="btn btn-primary"><i class="fas fa-plus mr-1"></i> New task</a>
</div>

<div class="row">
  @php($cards = [
    ['Total tasks', $s['total'], 'fa-list-check', 'var(--teal-2)', route('tasks.index')],
    ['Pending', $s['pending'], 'fa-clock', '#e0a800', route('tasks.index', ['status' => 'pending'])],
    ['Completed', $s['completed'], 'fa-circle-check', '#28a745', route('tasks.index', ['status' => 'completed'])],
    ['Overdue', $s['overdue'], 'fa-triangle-exclamation', '#dc3545', route('tasks.index', ['due' => 'overdue'])],
  ])
  @foreach ($cards as [$label, $n, $icon, $color, $href])
    <div class="col-6 col-lg-3 mb-3">
      <a href="{{ $href }}" class="stat-link"><div class="card stat">
        <div class="n" style="color:{{ $color }}">{{ $n }}</div><div class="l">{{ $label }}</div><i class="fas {{ $icon }} ic" style="color:{{ $color }}"></i>
      </div></a>
    </div>
  @endforeach
</div>

<div class="row">
  <div class="col-lg-8 mb-3">
    <div class="card h-100"><div class="card-header d-flex justify-content-between align-items-center">Completed in the last 14 days <span class="badge badge-soft">{{ $s['rate'] }}% overall</span></div>
      <div class="card-body"><div class="chart-box"><canvas id="trendChart"></canvas></div></div></div>
  </div>
  <div class="col-lg-4 mb-3">
    <div class="card h-100"><div class="card-header">Pending by priority</div>
      <div class="card-body"><div class="chart-box"><canvas id="priorityChart"></canvas></div></div></div>
  </div>
</div>

<div class="row">
  <div class="col-lg-6 mb-3">
    <div class="card h-100"><div class="card-header">Coming up</div>
      <ul class="list-group list-group-flush">
        @forelse ($s['upcoming'] as $t)
          <li class="list-group-item d-flex justify-content-between align-items-center">
            <a href="{{ route('tasks.show', $t) }}" class="text-truncate mr-2" style="color:var(--text)">{{ $t->title }}</a>
            <span class="badge {{ $t->is_overdue ? 'badge-danger' : 'badge-soft' }}">{{ $t->is_overdue ? 'Overdue · ' : '' }}{{ $t->due_date->format('d M') }}</span>
          </li>
        @empty
          <li class="list-group-item text-muted">Nothing scheduled. Add due dates to see tasks here.</li>
        @endforelse
      </ul>
    </div>
  </div>
  <div class="col-lg-6 mb-3">
    <div class="card h-100"><div class="card-header">Recent activity</div>
      <div class="card-body py-2"><ul class="timeline">
        @forelse ($s['recent'] as $a)
          <li><i class="fas {{ $a->icon() }}"></i><div>{{ $a->description }}<br><small>{{ $a->user?->name ?? 'System' }} · {{ $a->created_at->diffForHumans() }}@if($a->source === 'ai') · <span class="badge badge-gold" style="background:var(--gold);color:#1a1500">AI</span>@endif</small></div></li>
        @empty <li class="text-muted">No activity yet.</li> @endforelse
      </ul></div>
    </div>
  </div>
</div>

@if ($s['workload']->isNotEmpty())
<div class="card mb-3"><div class="card-header">Team workload</div>
  <div class="table-responsive"><table class="table table-hover mb-0">
    <thead><tr><th>Person</th><th>Role</th><th class="text-center">Pending</th><th class="text-center">Completed</th></tr></thead>
    <tbody>@foreach ($s['workload'] as $p)
      <tr><td><span class="avatar avatar-sm mr-2" style="background:{{ $p->avatar_color }}">{{ $p->initials }}</span>{{ $p->name }}</td>
      <td><span class="badge badge-{{ $p->role->badge() }}">{{ $p->role->label() }}</span></td>
      <td class="text-center"><a href="{{ route('tasks.index', ['assignee' => $p->id, 'status' => 'pending']) }}">{{ $p->pending_count }}</a></td><td class="text-center">{{ $p->done_count }}</td></tr>
    @endforeach</tbody></table></div></div>
@endif
@endsection

@push('scripts')
<script src="{{ asset('vendor/chartjs/chart.umd.js') }}"></script>
<script>
(function () {
  var trend = @json($s['trend']), prio = @json($s['priority']), charts = [];
  function css(v) { return getComputedStyle(document.documentElement).getPropertyValue(v).trim(); }
  window.refreshCharts = function () { charts.forEach(function (c) { c.destroy(); }); charts = []; draw(); };
  function draw() {
    var text = css('--text-2'), grid = css('--border'), teal = '#38a3a5';
    Chart.defaults.color = text; Chart.defaults.font.family = 'Inter, system-ui, sans-serif';
    charts.push(new Chart(document.getElementById('trendChart'), {
      type: 'bar',
      data: { labels: trend.map(function (d) { return d.label; }), datasets: [{ data: trend.map(function (d) { return d.value; }), backgroundColor: teal, borderRadius: 6, maxBarThickness: 26 }] },
      options: { maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: grid } }, x: { grid: { display: false } } } }
    }));
    charts.push(new Chart(document.getElementById('priorityChart'), {
      type: 'doughnut',
      data: { labels: prio.map(function (d) { return d.label; }), datasets: [{ data: prio.map(function (d) { return d.value; }), backgroundColor: ['#6c757d', '#17a2b8', '#e0a800', '#dc3545'], borderColor: css('--surface'), borderWidth: 3 }] },
      options: { maintainAspectRatio: false, cutout: '62%', plugins: { legend: { position: 'bottom' } } }
    }));
  }
  draw();
})();
</script>
@endpush
