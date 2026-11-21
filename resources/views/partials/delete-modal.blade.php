{{-- ONE shared confirmation modal. Any element with data-delete-url / data-delete-name opens it. --}}
<div class="modal fade" id="deleteModal" tabindex="-1" role="dialog" aria-labelledby="deleteTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <form class="modal-content" id="deleteForm" method="POST" action="#">
      @csrf @method('DELETE')
      <div class="modal-header">
        <h5 class="modal-title" id="deleteTitle"><i class="fas fa-triangle-exclamation text-danger mr-2"></i>Delete this?</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
      </div>
      <div class="modal-body">
        <p class="mb-1">You are about to delete <strong id="deleteName">this item</strong>.</p>
        <p class="text-muted mb-0" id="deleteNote"></p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-danger" id="deleteConfirm"><i class="fas fa-trash mr-1"></i> Yes, delete</button>
      </div>
    </form>
  </div>
</div>
