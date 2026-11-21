@if (session('success'))
  <div class="alert alert-success auto-dismiss" role="alert"><i class="fas fa-circle-check mr-2"></i>{{ session('success') }}</div>
@endif
@if (session('error'))
  <div class="alert alert-danger" role="alert"><i class="fas fa-triangle-exclamation mr-2"></i>{{ session('error') }}</div>
@endif
