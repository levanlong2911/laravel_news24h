<div class="modal fade" id="deleteModel">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form action="{{ $url }}" method="post">
                @csrf
                <input type="hidden" name="ids[]" value="{{ $id }}">
                <div class="modal-body">
                    <p style="text-align: center; padding-top: 20px;">{{ __('modal.content_delete_model') }}</p>
                </div>
                <div class="modal-footer justify-content-between">
                    <button type="button" class="btn button-back"
                        data-dismiss="modal">{{ __('modal.cancel') }}</button>
                    <button type="submit" class="btn btn-danger btn-main--modal text-light">{{ __('modal.submit_delete_model') }}</button>
                </div>
            </form>
        </div>
        <!-- /.modal-content -->
    </div>
    <!-- /.modal-dialog -->
</div>
