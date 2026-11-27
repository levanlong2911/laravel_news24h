{{--
    One film and the sources it pins: the film the project approves now, or the film a package revision was written for.
    Shares the helpers of the screen ($mmss, $ratio, $id, $finalUrl). Given: $film (?array), $pins (?array), $assets, $refs,
    $missing, $heading, $badge (html), $scopeClass and, for the film of the project, $sourceButton.
--}}
<section class="sp-card {{ $scopeClass }}" data-social-film data-sp-film="{{ $film['final_id'] ?? '' }}">
    <div class="sp-card-head">
        <h3>{{ $heading }}</h3>
        {!! $badge !!}
    </div>
    @if($film === null)
        <div class="sp-media"><div class="sp-media-empty"><div><i class="fas fa-film" style="font-size:26px"></i><br>Chưa có phim</div></div></div>
        <div class="sp-alert info" style="margin:12px 0 0"><i class="fas fa-info-circle"></i><div>Chưa có phim đã duyệt dùng được. Social Publishing chỉ nhận bản final đã duyệt ở Final Composition và file còn khớp hash; màn này không dựng lại phim. <a href="{{ $finalUrl }}">Mở Final Composition</a></div></div>
    @else
        <div class="sp-media">
            <video src="{{ route('video-projects.final-file', [$id, $film['final_id']]) }}" controls preload="metadata" playsinline></video>
            <span class="sp-media-tag tl">{{ $ratio($film['media']['width'] ?? null, $film['media']['height'] ?? null) }}</span>
            <span class="sp-media-tag br">{{ $mmss($film['media']['duration_ms'] ?? null) }}</span>
        </div>
        <ul class="sp-facts">
            <li><i class="fas fa-film"></i><span>Final</span><b>r{{ $film['revision'] }} · {{ substr($film['final_id'], 0, 8) }}… <button type="button" class="sp-copy" data-sp-copy="{{ $film['final_id'] }}" title="Sao chép ID bản final"><i class="far fa-copy"></i></button></b></li>
            <li><i class="fas fa-fingerprint"></i><span>Hash</span><b data-social-film-sha>{{ substr($film['sha256'], 0, 14) }}… <button type="button" class="sp-copy" data-sp-copy="{{ $film['sha256'] }}" title="Sao chép hash file"><i class="far fa-copy"></i></button></b></li>
            <li><i class="fas fa-code-branch"></i><span>Revision</span><b><span class="sp-badge ok">R{{ $film['revision'] }} (Approved)</span></b></li>
            <li><i class="fas fa-expand"></i><span>Độ phân giải</span><b>{{ $film['media']['width'] }} × {{ $film['media']['height'] }}</b></li>
            <li><i class="far fa-clock"></i><span>Thời lượng</span><b>{{ $mmss($film['media']['duration_ms']) }} · {{ $film['media']['fps'] ?? '?' }} fps</b></li>
            <li><i class="fas fa-volume-up"></i><span>File</span><b>{{ $film['media']['has_audio'] ? 'có âm thanh' : 'không âm thanh' }} · {{ number_format($film['media']['bytes'] / 1048576, 1) }} MB · {{ $film['media']['container'] }}</b></li>
            <li><i class="fas fa-stamp"></i><span>Quyết định duyệt</span><b>{{ $film['approved_at'] ? \Illuminate\Support\Carbon::parse($film['approved_at'])->format('Y-m-d H:i') : '—' }}{{ ($film['approved_by_name'] ?? null) ? ' · '.$film['approved_by_name'] : '' }} <a href="{{ $finalUrl }}" title="Mở Final Composition"><i class="fas fa-external-link-alt"></i></a></b></li>
        </ul>
        @foreach($missing as $gap)
            <div class="sp-alert" style="margin:10px 0 0" data-social-missing><i class="fas fa-exclamation-triangle"></i><div>Thiếu nguồn — {{ $gap }}</div></div>
        @endforeach
    @endif
</section>

@if($film !== null && is_array($pins))
    <section class="sp-card {{ $scopeClass }}" data-social-chain>
        <div class="sp-card-head"><h3>Chuỗi nguồn đã ghim</h3></div>
        <div class="sp-chain">
            <details>
                <summary><i class="fas fa-check-circle"></i><b>Kịch bản (Script)</b><em>#{{ substr((string) $pins['script']['stage_id'], 0, 8) }}</em></summary>
                <div class="sp-chain-body">Bản kịch bản mà phim ghim — {{ $pins['script']['stage_id'] }}<br>content hash {{ $pins['script']['content_hash'] }}</div>
            </details>
            <details>
                <summary><i class="fas {{ $pins['design'] ? 'fa-check-circle' : 'fa-exclamation-circle' }}"></i><b>Thiết kế (Design)</b><em>{{ $pins['design'] ? '#'.substr((string) $pins['design']['stage_id'], 0, 8) : 'không ghim' }}</em></summary>
                <div class="sp-chain-body">{{ $pins['design'] ? 'Bản thiết kế mà kịch bản ghim — '.$pins['design']['stage_id'].' · raw sha256 '.$pins['design']['raw_sha256'] : 'Kịch bản của phim không ghim bản thiết kế nào — gói không có dữ liệu thiết kế để dẫn nguồn.' }}</div>
            </details>
            <details>
                <summary><i class="fas fa-check-circle"></i><b>Shot plan</b><em>rev {{ $pins['shots']['revision'] }} · {{ $film['shots'] }} shot</em></summary>
                <div class="sp-chain-body">Kế hoạch shot {{ $pins['shots']['planning_stage_id'] }}<br>film plan {{ $pins['film_plan_hash'] }}</div>
            </details>
            <details>
                <summary><i class="fas fa-check-circle"></i><b>Tài sản (Assets)</b><em>{{ count($assets) }} ảnh/frame</em></summary>
                <div class="sp-chain-body">Ảnh và frame dùng làm cover: {{ implode(', ', array_column($assets, 'id')) ?: 'không có' }}</div>
            </details>
            <details>
                <summary><i class="fas fa-check-circle"></i><b>Dẫn nguồn</b><em>{{ $refs }} mục</em></summary>
                <div class="sp-chain-body">Mỗi khẳng định trong gói phải trỏ tới một mục ở đây: kịch bản, beat, shot, card chữ, thiết kế của chính bản phim.</div>
            </details>
        </div>
        @if($sourceButton ?? false)
            <button type="button" class="sp-btn outline block" style="margin-top:12px" data-sp-open="sp-modal-source"><i class="far fa-eye"></i> Xem chi tiết nguồn</button>
        @endif
    </section>
@endif
