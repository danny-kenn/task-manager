@extends('layouts.app')
@section('title', 'Activity log')
@section('heading', 'Activity log')
@section('content')
<div class="page-head"><div><h2>Activity log</h2><p>An append-only audit trail of who did what, including actions taken through the AI assistant.</p></div></div>
<div class="card mb-3"><form method="GET" class="toolbar form-row align-items-center">
  <div class="col-12 col-md mb-2 mb-md-0"><input type="search" name="q" value="{{ request('q') }}" class="form-control" placeholder="Search descriptions…"></div>
  <div class="col-6 col-md-auto mb-2 mb-md-0"><select name="action" class="custom-select"><option value="">All actions</option>@foreach ($actions as $a)<option value="{{ $a }}" @selected(request('action') === $a)>{{ $a }}</option>@endforeach</select></div>
  <div class="col-6 col-md-auto mb-2 mb-md-0"><select name="user_id" class="custom-select"><option value="">Everyone</option>@foreach ($people as $p)<option value="{{ $p->id }}" @selected(request('user_id') == $p->id)>{{ $p->name }}</option>@endforeach</select></div>
  <div class="col-6 col-md-auto mb-2 mb-md-0"><select name="source" class="custom-select"><option value="">Web + AI</option><option value="web" @selected(request('source') === 'web')>Web</option><option value="ai" @selected(request('source') === 'ai')>AI</option></select></div>
  <div class="col-auto"><button class="btn btn-primary">Filter</button> @if (request()->query())<a href="{{ route('activity.index') }}" class="btn btn-light">Reset</a>@endif</div>
</form></div>
<div class="card"><div class="table-responsive"><table class="table table-hover mb-0">
  <thead><tr><th style="width:40px"></th><th>What happened</th><th>Who</th><th>Source</th><th>When</th></tr></thead>
  <tbody>@forelse ($logs as $l)
    <tr><td><i class="fas {{ $l->icon() }}"></i></td>
    <td>{{ $l->description }} @if ($l->task_id)<a href="{{ route('tasks.show', $l->task_id) }}" class="small">#{{ $l->task_id }}</a>@endif
      @if ($l->properties && $l->action === 'updated')<br><small class="mono">@foreach ($l->properties as $k => $v)@if (is_array($v)){{ $k }}: {{ $v['from'] ?? '∅' }} → {{ $v['to'] ?? '∅' }}@if (! $loop->last) · @endif @endif @endforeach</small>@endif</td>
    <td>{{ $l->user?->name ?? 'System' }}</td>
    <td>@if ($l->source === 'ai')<span class="badge" style="background:var(--gold);color:#1a1500"><svg class="tm-ai-logo" viewBox="0 0 32 32" width="1em" height="1em" aria-hidden="true"><use href="#tm-logo"/></svg> AI</span>@else<span class="badge badge-soft">Web</span>@endif</td>
    <td title="{{ $l->created_at->inUserTz()->format('d M Y H:i:s T') }}">{{ $l->created_at->diffForHumans() }}</td></tr>
  @empty <tr><td colspan="5" class="text-center text-muted py-4">Nothing logged yet.</td></tr> @endforelse</tbody></table></div>
  <div class="card-footer">{{ $logs->links() }}</div></div>
@endsection
