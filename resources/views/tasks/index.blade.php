@extends('layouts.app')
@section('title', 'Tasks')
@section('heading', 'Tasks')
@section('content')
@php($f = $filters)
<div class="page-head">
  <div><h2>My tasks</h2><p>{{ auth()->user()->isStaff() ? 'Every task in the workspace.' : 'Tasks you own or that are assigned to you.' }}</p></div>
  <a href="{{ route('tasks.create') }}" class="btn btn-primary"><i class="fas fa-plus mr-1"></i> New task</a>
</div>

<div class="card mb-3">
  <form method="GET" action="{{ route('tasks.index') }}" class="toolbar">
    <div class="form-row align-items-center">
      <div class="col-12 col-md mb-2 mb-md-0">
        <div class="input-group">
          <div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-magnifying-glass"></i></span></div>
          <input type="search" name="q" value="{{ $f['q'] ?? '' }}" class="form-control" placeholder="Search by title, description, tag or status…" aria-label="Search tasks">
        </div>
      </div>
      <div class="col-6 col-md-auto mb-2 mb-md-0">
        <select name="status" class="custom-select" onchange="this.form.submit()" aria-label="Status">
          <option value="">All statuses</option>
          @foreach (\App\Enums\TaskStatus::cases() as $s)<option value="{{ $s->value }}" @selected(($f['status'] ?? '') === $s->value)>{{ $s->label() }}</option>@endforeach
        </select>
      </div>
      <div class="col-6 col-md-auto mb-2 mb-md-0">
        <select name="sort" class="custom-select" onchange="this.form.submit()" aria-label="Sort">
          @foreach (['manual' => 'My order (drag)', 'newest' => 'Newest first', 'oldest' => 'Oldest first', 'due' => 'Due date', 'priority' => 'Priority', 'title' => 'Title A–Z'] as $k => $v)<option value="{{ $k }}" @selected(($f['sort'] ?? 'manual') === $k)>{{ $v }}</option>@endforeach
        </select>
      </div>
      <div class="col-auto">
        <button class="btn btn-primary" type="submit">Search</button>
        <button class="btn btn-light" type="button" id="filterToggle" title="More filters"><i class="fas fa-sliders"></i></button>
        @if ($hasFilters)<a class="btn btn-light" href="{{ route('tasks.index') }}" title="Clear filters"><i class="fas fa-xmark"></i></a>@endif
      </div>
    </div>
    <div class="collapse {{ collect($f)->only(['priority', 'category_id', 'assignee', 'due', 'tag'])->filter()->isNotEmpty() ? 'show' : '' }}" id="filters">
      <div class="form-row mt-3">
        <div class="col-6 col-md-3 mb-2"><label>Priority</label><select name="priority" class="custom-select custom-select-sm"><option value="">Any</option>@foreach (\App\Enums\TaskPriority::cases() as $p)<option value="{{ $p->value }}" @selected(($f['priority'] ?? '') === $p->value)>{{ $p->label() }}</option>@endforeach</select></div>
        <div class="col-6 col-md-3 mb-2"><label>Category</label><select name="category_id" class="custom-select custom-select-sm"><option value="">Any</option>@foreach ($categories as $c)<option value="{{ $c->id }}" @selected(($f['category_id'] ?? '') == $c->id)>{{ $c->name }}</option>@endforeach</select></div>
        <div class="col-6 col-md-3 mb-2"><label>Due</label><select name="due" class="custom-select custom-select-sm"><option value="">Any time</option>@foreach (['overdue' => 'Overdue', 'today' => 'Today', 'week' => 'Next 7 days', 'none' => 'No due date'] as $k => $v)<option value="{{ $k }}" @selected(($f['due'] ?? '') === $k)>{{ $v }}</option>@endforeach</select></div>
        @if ($people->isNotEmpty())
        <div class="col-6 col-md-3 mb-2"><label>Assignee</label><select name="assignee" class="custom-select custom-select-sm"><option value="">Anyone</option><option value="me" @selected(($f['assignee'] ?? '') === 'me')>Me</option>@foreach ($people as $p)<option value="{{ $p->id }}" @selected(($f['assignee'] ?? '') == $p->id)>{{ $p->name }}</option>@endforeach</select></div>
        @endif
        <div class="col-6 col-md-3 mb-2"><label>Tag</label><select name="tag" class="custom-select custom-select-sm"><option value="">Any</option>@foreach ($tagList as $t)<option value="{{ $t->slug }}" @selected(($f['tag'] ?? '') === $t->slug)>#{{ $t->name }}</option>@endforeach</select></div>
        <div class="col-6 col-md-3 mb-2"><label>Per page</label><select name="per_page" class="custom-select custom-select-sm">@foreach ([10, 20, 50] as $n)<option value="{{ $n }}" @selected((int) request('per_page', 10) === $n)>{{ $n }}</option>@endforeach</select></div>
      </div>
    </div>
  </form>
</div>

<div class="card">
  @if ($tasks->count())
    <ul class="task-list" id="taskList" data-sortable="{{ $canDrag ? '1' : '0' }}" data-reorder-url="{{ route('tasks.reorder') }}">
      @foreach ($tasks as $task)
        @include('tasks._row', ['task' => $task, 'canDrag' => $canDrag, 'statusFilter' => $f['status'] ?? null])
      @endforeach
    </ul>
    <div class="card-footer d-flex flex-wrap justify-content-between align-items-center">
      <small>Showing {{ $tasks->firstItem() }}–{{ $tasks->lastItem() }} of {{ $tasks->total() }} task{{ $tasks->total() === 1 ? '' : 's' }}@if ($canDrag) · drag <i class="fas fa-grip-vertical"></i> to reorder @endif</small>
      <div class="mt-2 mt-md-0">{{ $tasks->links() }}</div>
    </div>
  @else
    <div class="empty">
      <i class="fas {{ $hasFilters ? 'fa-magnifying-glass' : 'fa-clipboard-list' }}"></i>
      @if ($hasFilters)
        <h5>No tasks match your search</h5><p>Try different words or <a href="{{ route('tasks.index') }}">clear the filters</a>.</p>
      @else
        <h5>No tasks yet</h5><p>Create your first task to get started.</p><a href="{{ route('tasks.create') }}" class="btn btn-primary"><i class="fas fa-plus mr-1"></i> New task</a>
      @endif
    </div>
  @endif
</div>
@endsection
