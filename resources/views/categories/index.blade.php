@extends('layouts.app')
@section('title', 'Categories')
@section('heading', 'Categories')
@section('content')
<div class="page-head"><div><h2>Categories</h2><p>Group tasks by area of work or life.</p></div></div>
<div class="row">
  <div class="col-lg-4 mb-3">
    <div class="card"><div class="card-header">New category</div><div class="card-body">
      <form method="POST" action="{{ route('categories.store') }}" novalidate class="once">@csrf
        @include('categories._fields', ['category' => null, 'bag' => 'new'])
        <button class="btn btn-primary btn-block" type="submit"><i class="fas fa-plus mr-1"></i> Add category</button>
      </form></div></div>
  </div>
  <div class="col-lg-8 mb-3">
    <div class="card">
      @if ($categories->count())
      <div class="table-responsive"><table class="table table-hover mb-0">
        <thead><tr><th>Name</th><th>Description</th><th class="text-center">Tasks</th><th class="text-right">Actions</th></tr></thead>
        <tbody>@foreach ($categories as $c)
          <tr><td><span class="dot mr-2" style="background:{{ $c->color }}"></span><strong>{{ $c->name }}</strong></td><td class="text-muted">{{ $c->description ?: '—' }}</td>
          <td class="text-center"><a href="{{ route('tasks.index', ['category_id' => $c->id]) }}">{{ $c->tasks_count }}</a></td>
          <td class="text-right">
            <button class="btn btn-sm btn-light" data-toggle="modal" data-target="#edit{{ $c->id }}"><i class="fas fa-pen"></i></button>
            <button class="btn btn-sm btn-light text-danger" data-delete-url="{{ route('categories.destroy', $c) }}" data-delete-name="{{ $c->name }}" data-delete-note="Its {{ $c->tasks_count }} task(s) will become uncategorised, not deleted."><i class="fas fa-trash"></i></button>
          </td></tr>
        @endforeach</tbody></table></div>
      @else <div class="empty"><i class="fas fa-tags"></i><h5>No categories yet</h5></div> @endif
    </div>
  </div>
</div>

@foreach ($categories as $c)
<div class="modal fade" id="edit{{ $c->id }}" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><form class="modal-content" method="POST" action="{{ route('categories.update', $c) }}" novalidate>
  @csrf @method('PUT')
  <div class="modal-header"><h5 class="modal-title">Edit category</h5><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button></div>
  <div class="modal-body">@include('categories._fields', ['category' => $c, 'bag' => 'edit'.$c->id])</div>
  <div class="modal-footer"><button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button><button class="btn btn-primary">Save</button></div>
</form></div></div>
@endforeach

@if ($errors->any() && old('_modal'))@push('scripts')<script>$('#{{ old('_modal') }}').modal('show');</script>@endpush @endif
@endsection
