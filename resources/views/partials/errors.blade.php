@if ($errors->any())
  <div class="alert alert-danger" role="alert">
    <strong><i class="fas fa-triangle-exclamation mr-1"></i> Please fix the following:</strong>
    <ul class="mb-0 pl-4 mt-1">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
  </div>
@endif
