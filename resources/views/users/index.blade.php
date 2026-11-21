@extends('layouts.app')
@section('title', 'Users')
@section('heading', 'Users & roles')
@section('content')
<div class="page-head"><div><h2>Users &amp; roles</h2><p>Only administrators can see this page.</p></div>
  <a href="{{ route('users.create') }}" class="btn btn-primary"><i class="fas fa-user-plus mr-1"></i> New user</a></div>

<div class="row mb-3">
  @foreach (\App\Enums\UserRole::cases() as $r)
    <div class="col-md-4 mb-2"><div class="card h-100"><div class="card-body py-3"><span class="badge badge-{{ $r->badge() }} mb-1">{{ $r->label() }}</span><div class="text-muted" style="font-size:.84rem">{{ $r->description() }}</div></div></div></div>
  @endforeach
</div>

<div class="card mb-3"><form method="GET" class="toolbar form-row align-items-center">
  <div class="col mb-2 mb-md-0"><input type="search" name="q" value="{{ request('q') }}" class="form-control" placeholder="Search name or email…"></div>
  <div class="col-auto mb-2 mb-md-0"><select name="role" class="custom-select" onchange="this.form.submit()"><option value="">All roles</option>@foreach (\App\Enums\UserRole::cases() as $r)<option value="{{ $r->value }}" @selected(request('role') === $r->value)>{{ $r->label() }}</option>@endforeach</select></div>
  <div class="col-auto"><button class="btn btn-primary">Filter</button></div>
</form></div>

<div class="card"><div class="table-responsive"><table class="table table-hover mb-0">
  <thead><tr><th>User</th><th>Role</th><th>Status</th><th class="text-center">Owned</th><th class="text-center">Assigned</th><th>Last sign-in</th><th class="text-right">Actions</th></tr></thead>
  <tbody>@forelse ($users as $u)
    <tr><td><span class="avatar mr-2" style="background:{{ $u->avatar_color }}">{{ $u->initials }}</span><strong>{{ $u->name }}</strong><br><small class="ml-5 pl-1">{{ $u->email }}</small></td>
    <td><span class="badge badge-{{ $u->role->badge() }}">{{ $u->role->label() }}</span></td>
    <td>@if ($u->is_active)<span class="badge badge-success">Active</span>@else<span class="badge badge-secondary">Deactivated</span>@endif</td>
    <td class="text-center">{{ $u->tasks_count }}</td><td class="text-center">{{ $u->assigned_tasks_count }}</td>
    <td>{{ $u->last_login_at?->diffForHumans() ?? 'Never' }}</td>
    <td class="text-right">
      <a class="btn btn-sm btn-light" href="{{ route('users.edit', $u) }}"><i class="fas fa-pen"></i></a>
      @if ($u->id !== auth()->id())<button class="btn btn-sm btn-light text-danger" data-delete-url="{{ route('users.destroy', $u) }}" data-delete-name="{{ $u->name }}" data-delete-note="Their own tasks are deleted with them; tasks assigned to them become unassigned."><i class="fas fa-trash"></i></button>@endif
    </td></tr>
  @empty <tr><td colspan="7" class="text-center text-muted py-4">No users match.</td></tr> @endforelse</tbody></table></div>
  <div class="card-footer">{{ $users->links() }}</div></div>
@endsection
