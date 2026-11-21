@php($mine = ! $category || old('_modal') === 'edit'.$category->id)
<input type="hidden" name="_modal" value="{{ $category ? 'edit'.$category->id : '' }}">
<div class="form-group"><label>Name <span class="text-danger">*</span></label>
  <input name="name" maxlength="60" value="{{ $mine && old('name') !== null ? old('name') : ($category->name ?? '') }}" class="form-control @if ($mine) @error('name') is-invalid @enderror @endif">
  @if ($mine) @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror @endif</div>
<div class="form-group"><label>Colour</label>
  <input type="color" name="color" value="{{ $mine && old('color') ? old('color') : ($category->color ?? '#2c7a7b') }}" class="form-control" style="height:40px;padding:4px"></div>
<div class="form-group"><label>Description</label>
  <input name="description" maxlength="255" value="{{ $mine && old('description') !== null ? old('description') : ($category->description ?? '') }}" class="form-control"></div>
