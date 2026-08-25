<div class="modal fade" id="{{ $id }}"data-backdrop="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-body">
                <p class="p-3 text-center mb-0">{{ $content }}</p>
                @if(($detail ?? '') !== '')
                    <p class="text-center text-muted mb-0" style="font-size:.82rem">{{ $detail }}</p>
                @endif
            </div>
            <div class="modal-footer justify-content-between">
                <button type="button" class="btn button-back" data-dismiss="modal">{{ __('modal.cancel') }}</button>
                <button type="submit" class="btn btn-primary" form="{{ $form }}">{{ $agree ?? __('modal.agree') }}</button>
            </div>
        </div>
    </div>
</div>
