@extends('layouts.app')
@section('title', $task->title)
@section('heading', 'Task')
@section('content')
<div class="page-head">
  <div>
    <nav class="breadcrumb"><a href="{{ route('tasks.index') }}">Tasks</a><span class="mx-2">/</span>#{{ $task->id }}</nav>
    <h2 class="{{ $task->is_completed ? 'text-muted' : '' }}" style="{{ $task->is_completed ? 'text-decoration:line-through' : '' }}">{{ $task->title }}</h2>
    <p><span class="badge badge-{{ $task->status->badge() }}"><i class="fas {{ $task->status->icon() }}"></i> {{ $task->status->label() }}</span>
       <span class="badge badge-{{ $task->priority->badge() }}">{{ $task->priority->label() }} priority</span>
       @if ($task->is_overdue)<span class="badge badge-danger">Overdue</span>@endif</p>
  </div>
  <div class="d-flex flex-wrap" style="gap:.5rem">
    @can('toggle', $task)
      <form method="POST" action="{{ route('tasks.toggle', $task) }}">@csrf @method('PATCH')
        <button class="btn {{ $task->is_completed ? 'btn-outline-secondary' : 'btn-success' }}" type="submit"><i class="fas {{ $task->is_completed ? 'fa-rotate-left' : 'fa-check' }} mr-1"></i> {{ $task->is_completed ? 'Reopen' : 'Mark completed' }}</button>
      </form>
    @endcan
    @can('update', $task)<a href="{{ route('tasks.edit', $task) }}" class="btn btn-light"><i class="fas fa-pen mr-1"></i> Edit</a>@endcan
    @can('delete', $task)<button class="btn btn-outline-danger" data-delete-url="{{ route('tasks.destroy', $task) }}" data-delete-name="{{ $task->title }}" data-delete-note="It moves to the trash, so it can be restored by a manager or admin."><i class="fas fa-trash mr-1"></i> Delete</button>@endcan
  </div>
</div>

<div class="row">
  <div class="col-lg-8">
    <div class="card mb-3"><div class="card-header">Description</div>
      <div class="card-body">{!! $task->description ? nl2br(e($task->description)) : '<span class="text-muted">No description.</span>' !!}</div></div>

    <div class="card mb-3" id="checklist"><div class="card-header d-flex justify-content-between align-items-center">Checklist <span class="badge badge-soft">{{ $task->subtasks->where('is_done', true)->count() }}/{{ $task->subtasks->count() }}</span></div>
      <div class="card-body">
        @if ($task->subtasks->count())<div class="progress mb-3" style="height:8px"><div class="progress-bar" style="width:{{ $task->progress }}%"></div></div>@endif
        @forelse ($task->subtasks as $st)
          <div class="sub-item {{ $st->is_done ? 'done' : '' }}">
            @can('update', $task)
              <form method="POST" action="{{ route('subtasks.toggle', [$task, $st]) }}">@csrf @method('PATCH')<button class="check-btn {{ $st->is_done ? '' : '' }}" style="{{ $st->is_done ? 'background:#28a745;border-color:#28a745;color:#fff' : '' }}" title="Toggle"><i class="fas fa-check"></i></button></form>
            @endcan
            <span class="flex-fill">{{ $st->title }}</span>
            @can('update', $task)<form method="POST" action="{{ route('subtasks.destroy', [$task, $st]) }}">@csrf @method('DELETE')<button class="btn btn-sm btn-link text-muted" title="Remove"><i class="fas fa-xmark"></i></button></form>@endcan
          </div>
        @empty <p class="text-muted mb-2">Break the task into small steps.</p> @endforelse
        @can('update', $task)
          <form method="POST" action="{{ route('subtasks.store', $task) }}" class="mt-3">@csrf
            <div class="input-group"><input name="title" class="form-control @error('title') is-invalid @enderror" placeholder="Add a checklist item…" maxlength="150" value="{{ old('title') }}">
            <div class="input-group-append"><button class="btn btn-primary" type="submit"><i class="fas fa-plus"></i></button></div></div>
            @error('title')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
          </form>
        @endcan
      </div></div>

    <div class="card mb-3" id="comments"><div class="card-header">Comments ({{ $task->comments->count() }})</div>
      <div class="card-body">
        @forelse ($task->comments as $c)
          <div class="comment">
            <span class="avatar" style="background:{{ $c->author->avatar_color }}">{{ $c->author->initials }}</span>
            <div class="flex-fill"><strong>{{ $c->author->name }}</strong> <small>· {{ $c->created_at->diffForHumans() }}</small>
              <div>{!! nl2br(e($c->body)) !!}</div></div>
            @if (auth()->user()->isStaff() || $c->user_id === auth()->id())
              <form method="POST" action="{{ route('comments.destroy', [$task, $c]) }}">@csrf @method('DELETE')<button class="btn btn-sm btn-link text-muted" title="Delete comment"><i class="fas fa-trash"></i></button></form>
            @endif
          </div>
        @empty <p class="text-muted">No comments yet.</p> @endforelse
        <form method="POST" action="{{ route('comments.store', $task) }}" class="mt-3">@csrf
          <textarea name="body" rows="2" maxlength="2000" class="form-control @error('body') is-invalid @enderror" placeholder="Write a comment…">{{ old('body') }}</textarea>
          @error('body')<div class="invalid-feedback">{{ $message }}</div>@enderror
          <button class="btn btn-primary btn-sm mt-2" type="submit">Post comment</button>
        </form>
      </div></div>
  </div>

  <div class="col-lg-4">
    <div class="card mb-3"><div class="card-header">Details</div>
      <div class="card-body"><dl class="kv mb-0">
        <dt>Status</dt><dd>{{ $task->status->label() }}</dd>
        <dt>Priority</dt><dd>{{ $task->priority->label() }}</dd>
        <dt>Due</dt><dd>{{ $task->due_date ? $task->due_date->format('D, d M Y') : '—' }}</dd>
        <dt>Category</dt><dd>@if ($task->category)<span class="dot" style="background:{{ $task->category->color }}"></span> {{ $task->category->name }}@else — @endif</dd>
        <dt>Owner</dt><dd>{{ $task->owner->name }}</dd>
        <dt>Assignee</dt><dd>{{ $task->assignee?->name ?? 'Unassigned' }}</dd>
        <dt>Created</dt><dd>{{ $task->created_at->format('d M Y, H:i') }}</dd>
        <dt>Updated</dt><dd>{{ $task->updated_at->diffForHumans() }}</dd>
        @if ($task->completed_at)<dt>Completed</dt><dd>{{ $task->completed_at->format('d M Y, H:i') }}</dd>@endif
        <dt>Tags</dt><dd>@forelse ($task->tags as $t)<a class="tag-chip" href="{{ route('tasks.index', ['tag' => $t->slug]) }}">#{{ $t->name }}</a> @empty — @endforelse</dd>
      </dl></div></div>
    <div class="card"><div class="card-header">History</div>
      <div class="card-body py-2"><ul class="timeline">
        @forelse ($history as $a)<li><i class="fas {{ $a->icon() }}"></i><div>{{ $a->description }}<br><small>{{ $a->user?->name ?? 'System' }} · {{ $a->created_at->diffForHumans() }}</small></div></li>
        @empty <li class="text-muted">No history yet.</li> @endforelse
      </ul></div></div>
  </div>
</div>
@endsection
