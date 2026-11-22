@php($overdue = $task->is_overdue)
<li class="task-row {{ $task->is_completed ? 'is-completed' : '' }} {{ $overdue ? 'is-overdue' : '' }}" id="task-{{ $task->id }}" data-id="{{ $task->id }}" @if (! empty($statusFilter)) data-filter-status="1" @endif>
  <span class="drag-handle {{ $canDrag ? '' : 'off' }}" title="Drag to reorder"><i class="fas fa-grip-vertical"></i></span>

  @can('toggle', $task)
    <form class="toggle-form" method="POST" action="{{ route('tasks.toggle', $task) }}">@csrf @method('PATCH')
      <button class="check-btn" type="submit" title="{{ $task->is_completed ? 'Mark as pending' : 'Mark as completed' }}" aria-label="Toggle status"><i class="fas fa-check"></i></button>
    </form>
  @endcan

  <div class="task-main">
    <a class="task-title" href="{{ route('tasks.show', $task) }}">{{ $task->title }}</a>
    <div class="task-meta">
      <span class="chip" title="Created {{ $task->created_at->format('d M Y, H:i') }}"><i class="far fa-calendar-plus"></i> Created {{ $task->created_at->format('d M Y') }}</span>
      @if ($task->due_date)
        <span class="chip {{ $overdue ? 'text-danger font-weight-bold' : '' }}"><i class="far fa-calendar-check"></i> {{ $overdue ? 'Overdue' : 'Due' }} {{ $task->due_date->format('d M') }}</span>
      @endif
      @if ($task->category)<span class="chip"><span class="dot" style="background:{{ $task->category->color }}"></span>{{ $task->category->name }}</span>@endif
      @if ($task->subtasks_count)<span class="chip"><i class="far fa-square-check"></i> {{ $task->subtasks_done_count }}/{{ $task->subtasks_count }}</span>@endif
      @if ($task->comments_count)<span class="chip"><i class="far fa-comment"></i> {{ $task->comments_count }}</span>@endif
      @if ($task->assignee && ($task->assignee_id !== auth()->id()))<span class="chip" title="Assigned to {{ $task->assignee->name }}"><x-avatar :user="$task->assignee" size="sm" />{{ $task->assignee->first_name }}</span>@endif
      @if ($task->owner && $task->user_id !== auth()->id() && $task->user_id !== $task->assignee_id)<span class="chip" title="Created by {{ $task->owner->name }}"><i class="far fa-user"></i><x-avatar :user="$task->owner" size="sm" /></span>@endif
      @foreach ($task->tags->take(3) as $tag)<a class="tag-chip" href="{{ route('tasks.index', ['tag' => $tag->slug]) }}">#{{ $tag->name }}</a>@endforeach
    </div>
  </div>

  <div class="task-side">
    <span class="badge badge-{{ $task->priority->badge() }}">{{ $task->priority->label() }}</span>
    <span class="badge badge-{{ $task->status->badge() }} status-badge"><i class="fas {{ $task->status->icon() }}"></i> {{ $task->status->label() }}</span>
    <span class="row-actions">
      <a class="btn btn-sm btn-light" href="{{ route('tasks.show', $task) }}" title="View"><i class="fas fa-eye"></i></a>
      @can('update', $task)<a class="btn btn-sm btn-light" href="{{ route('tasks.edit', $task) }}" title="Edit"><i class="fas fa-pen"></i></a>@endcan
      @can('delete', $task)<button type="button" class="btn btn-sm btn-light text-danger" title="Delete" data-delete-url="{{ route('tasks.destroy', $task) }}" data-delete-name="{{ $task->title }}" data-delete-note="It moves to the trash, so it can be restored by a manager or admin."><i class="fas fa-trash"></i></button>@endcan
    </span>
  </div>
</li>
