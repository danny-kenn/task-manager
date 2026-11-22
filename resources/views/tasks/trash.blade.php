@extends('layouts.app')
@section('title', 'Trash')
@section('heading', 'Trash')
@section('content')
<div class="page-head"><div><h2>Trash</h2><p>{{ auth()->user()->isStaff() ? 'Your deleted tasks and your team\'s work tasks.' : 'Only you can see your deleted tasks.' }} They are removed for good after 30 days; restore or delete them sooner here.</p></div></div>
<div class="card">
  @if ($tasks->count())
  <div class="table-responsive"><table class="table table-hover mb-0">
    <thead><tr><th>Task</th><th>Created by</th><th>Deleted</th><th class="text-right">Actions</th></tr></thead>
    <tbody>@foreach ($tasks as $t)
      <tr><td>{{ $t->title }}</td><td>{{ $t->owner?->name }}</td><td>{{ $t->deleted_at->diffForHumans() }}</td>
      <td class="text-right">
        <form class="d-inline" method="POST" action="{{ route('tasks.restore', $t->id) }}">@csrf<button class="btn btn-sm btn-light"><i class="fas fa-rotate-left mr-1"></i>Restore</button></form>
        @can('forceDelete', $t)<button class="btn btn-sm btn-light text-danger" data-delete-url="{{ route('tasks.force', $t->id) }}" data-delete-name="{{ $t->title }}" data-delete-note="This permanently removes the task, its checklist and comments. It cannot be undone."><i class="fas fa-fire mr-1"></i>Delete forever</button>@endcan
      </td></tr>
    @endforeach</tbody></table></div>
  <div class="card-footer">{{ $tasks->links() }}</div>
  @else <div class="empty"><i class="fas fa-trash-can"></i><h5>The trash is empty</h5></div> @endif
</div>
@endsection
