<!-- The Modal -->
<div class="modal fade" id="confirmModal">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <!-- Modal body -->
            <div class="modal-body">
                <form action="{{$url}}" method="post" id="delete-form">
                    @csrf
                    @foreach($ids as $id)
                        <input type="hidden" name="ids[]" value="{{$id}}">
                        @if ($memo[$id] ?? '')
                            <input type="hidden" name="memos[]" value="{{$memo[$id]}}">
                        @endif
                    @endforeach
                    <p class="p-3 text-center">{{ $content }}</p>
                    {{-- end row --}}
                    <div class="group-btn d-flex justify-content-center">
                        <button type="button" class="btn button-del btn-danger btn-main--modal btn-main--modal grey mr-2" data-dismiss="modal">閉じる</button>
                        <button type="button" onclick="deleteCookiesAndSubmit()" class="btn btn-main btn-main--modal">同意する</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
