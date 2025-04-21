<!-- The Modal -->
<div class="modal fade" id="confirmModal">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <!-- Modal body -->
            <div class="modal-body">
                <form action="{{$url}}" method="post">
                    @csrf
                    @foreach($ids as $id)
                        <input type="hidden" name="ids[]" value="{{$id}}">
                        @if ($memo[$id] ?? '')
                            <input type="hidden" name="memos[]" value="{{$memo[$id]}}">
                        @endif
                    @endforeach
                    {{-- @if ($userId != '')
                        <input type="hidden" name="userId" value="{{$userId}}">
                    @endif --}}
                    <p class="p-3 text-center">{{ $content }}</p>
                    {{-- end row --}}
                    <div class="modal-footer justify-content-between">
                        <button type="button" class="btn button-back"
                        data-dismiss="modal">{{ __('modal.cancel') }}</button>
                        <button type="submit" class="btn button-del btn-danger btn-main--modal text-light">{{ __('modal.agree') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
