@if($strip === null)
    <div class="d" style="color:var(--vp-amber-fg)">Chưa trích được dải ảnh duyệt: {{ $error }}</div>
@else
    <div class="vc-strip" data-review-key="{{ $strip['key'] }}">
        @foreach($strip['stills'] as $still)
            <figure class="vc-still" data-seek-ms="{{ $still['seek_ms'] }}" title="Bấm để tua trình phát tới {{ $still['at_ms'] }} ms">
                <img src="{{ route('video-projects.clip-still', [$id, $strip['render_id'], $strip['key'], $still['index']]) }}" alt="{{ $still['at_ms'] }} ms" loading="lazy">
                <figcaption>
                    <b>{{ $still['at_ms'] }} ms</b> · frame {{ $still['frame'] }}
                    @foreach($still['requirements'] as $need)
                        <div class="d"><b>{{ $need['source'] }}</b>: {{ $need['text'] }}</div>
                    @endforeach
                </figcaption>
            </figure>
        @endforeach
    </div>
    <div class="d">Ảnh hỗ trợ duyệt trích từ file thật ở các mốc của ShotSpec đã ghim (phần phim giữ {{ $strip['used']['start_ms'] }}–{{ $strip['used']['start_ms'] + $strip['used']['duration_ms'] }} ms) — chỉ để kiểm, không phải bằng chứng đã xem từng frame. Bấm ảnh để tua trình phát.</div>
@endif
