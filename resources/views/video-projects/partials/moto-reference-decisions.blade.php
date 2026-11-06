@foreach($reference['requirement']['decisions'] as $decision)
    <div class="vr-decision">
        <div class="va-lbl">{{ $reference['key'] }} — đã quyết định</div>
        <div>{{ $decision['question'] }} — {{ $decision['answer'] }}</div>
        @if($editable)
            <form method="POST" action="{{ route('video-projects.parts-detail', [$id, 'answer']) }}" onsubmit="return vpLockForm(this)">
                @csrf
                <input type="hidden" name="hash" value="{{ $preview['hash'] }}"><input type="hidden" name="reference_id" value="{{ $reference['id'] }}">
                <input type="hidden" name="decisions[0][question]" value="{{ $decision['question'] }}">
                <textarea class="va-ta" name="decisions[0][answer]" rows="2" maxlength="1000" required>{{ $decision['answer'] }}</textarea>
                <button class="vp-btn sm" data-busy="Đang lưu…">Lưu bản sửa, thay bản cũ (không gọi AI)</button>
            </form>
        @endif
    </div>
@endforeach
@foreach($reference['earlier_decisions'] as $earlier)
    <div class="vr-decision">
        <div class="va-lbl">{{ $reference['key'] }} — quyết định cũ, nguồn đã đổi nên chưa dùng</div>
        <div>{{ $earlier['question'] }} — {{ $earlier['answer'] }}</div>
        @if($editable)
            <form method="POST" action="{{ route('video-projects.parts-detail', [$id, 'answer']) }}" onsubmit="return vpLockForm(this)">
                @csrf
                <input type="hidden" name="hash" value="{{ $preview['hash'] }}"><input type="hidden" name="reference_id" value="{{ $reference['id'] }}">
                <input type="hidden" name="decisions[0][question]" value="{{ $earlier['question'] }}"><input type="hidden" name="decisions[0][answer]" value="{{ $earlier['answer'] }}">
                <button class="vp-btn sm" data-busy="Đang lưu…">Dùng lại cho nguồn hiện hành (không gọi AI)</button>
            </form>
        @endif
    </div>
@endforeach
