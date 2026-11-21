@php($isEdit = $task->exists)
<form method="POST" action="{{ $isEdit ? route('tasks.update', $task) : route('tasks.store') }}" novalidate class="once">
  @csrf @if ($isEdit) @method('PUT') @endif
  @include('partials.errors')

  <div class="form-group">
    <label for="title">Title <span class="text-danger">*</span></label>
    <input id="title" name="title" maxlength="150" value="{{ old('title', $task->title) }}" class="form-control @error('title') is-invalid @enderror" placeholder="What needs to be done?" aria-required="true" autofocus>
    @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
  </div>

  <div class="form-group">
    <label for="description">Description <small class="text-muted">(optional)</small></label>
    <textarea id="description" name="description" rows="4" maxlength="5000" data-count="#descCount" class="form-control @error('description') is-invalid @enderror" placeholder="Add details, links or acceptance criteria…">{{ old('description', $task->description) }}</textarea>
    <small class="form-text text-right"><span id="descCount">0</span>/5000</small>
    @error('description')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
  </div>

  <div class="form-row">
    <div class="form-group col-md-4">
      <label>Status <span class="text-danger">*</span></label>
      @php($cur = old('status', $task->status?->value ?? 'pending'))
      <div class="btn-group btn-group-toggle d-flex" data-toggle="buttons">
        @foreach (\App\Enums\TaskStatus::cases() as $s)
          <label class="btn btn-outline-{{ $s->badge() }} {{ $cur === $s->value ? 'active' : '' }}"><input type="radio" name="status" value="{{ $s->value }}" {{ $cur === $s->value ? 'checked' : '' }}><i class="fas {{ $s->icon() }} mr-1"></i>{{ $s->label() }}</label>
        @endforeach
      </div>
      @error('status')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
    </div>
    <div class="form-group col-md-4">
      <label for="priority">Priority</label>
      <select id="priority" name="priority" class="custom-select @error('priority') is-invalid @enderror">
        @foreach (\App\Enums\TaskPriority::cases() as $p)<option value="{{ $p->value }}" @selected(old('priority', $task->priority?->value ?? 'medium') === $p->value)>{{ $p->label() }}</option>@endforeach
      </select>
      @error('priority')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="form-group col-md-4">
      <label for="due_date">Due date</label>
      <input type="date" id="due_date" name="due_date" value="{{ old('due_date', $task->due_date?->format('Y-m-d')) }}" class="form-control @error('due_date') is-invalid @enderror">
      @error('due_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
  </div>

  <div class="form-row">
    <div class="form-group col-md-4">
      <label for="category_id">Category</label>
      <select id="category_id" name="category_id" class="custom-select @error('category_id') is-invalid @enderror">
        <option value="">No category</option>
        @foreach ($categories as $c)<option value="{{ $c->id }}" @selected(old('category_id', $task->category_id) == $c->id)>{{ $c->name }}</option>@endforeach
      </select>
      @error('category_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    @if ($people->isNotEmpty())
    <div class="form-group col-md-4">
      <label for="assignee_id">Assign to</label>
      <select id="assignee_id" name="assignee_id" class="custom-select @error('assignee_id') is-invalid @enderror">
        <option value="">Unassigned</option>
        @foreach ($people as $p)<option value="{{ $p->id }}" @selected(old('assignee_id', $task->assignee_id) == $p->id)>{{ $p->name }} ({{ $p->role->label() }})</option>@endforeach
      </select>
      @error('assignee_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    @endif
    <div class="form-group col-md-{{ $people->isNotEmpty() ? 4 : 8 }}">
      <label for="tags">Tags <small class="text-muted">(comma separated)</small></label>
      <input id="tags" name="tags" maxlength="255" value="{{ old('tags', $task->tags?->pluck('name')->implode(', ')) }}" class="form-control @error('tags') is-invalid @enderror" placeholder="laravel, urgent, client">
      @error('tags')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
  </div>

  <div class="d-flex" style="gap:.5rem">
    <button type="submit" class="btn btn-primary"><i class="fas fa-floppy-disk mr-1"></i> {{ $isEdit ? 'Save changes' : 'Create task' }}</button>
    <a href="{{ $isEdit ? route('tasks.show', $task) : route('tasks.index') }}" class="btn btn-light">Cancel</a>
  </div>
</form>
