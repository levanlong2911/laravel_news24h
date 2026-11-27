@extends('layouts.base', ['title' => 'Final Composition'])
@section('title', 'Final Composition')

@section('css')
<link rel="stylesheet" href="{{ asset('assets/css/final-composition.css') }}?v={{ filemtime(public_path('assets/css/final-composition.css')) }}">
@endsection

@section('content')
@php
    $clips = $composition['clips'];
    $finals = $composition['finals'];
    $latestFinal = $composition['latest_final'];
    $totalMs = (int) $composition['total_duration_ms'];
    // Du an moto: cung man nay, phim dung theo chinh sach Phase 11; null voi yacht.
    $motoFilm = $motoFilm ?? null;

    $clock = static function (int $ms): string {
        $seconds = (int) round($ms / 1000);

        return sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60);
    };

    $sequence = static fn (int $ordinal): string => 'S'.str_pad((string) $ordinal, 2, '0', STR_PAD_LEFT);

    $ticks = [];

    for ($i = 0; $i <= 5; $i++) {
        $ticks[] = $clock((int) round($totalMs * $i / 5));
    }

    $sizes = $composition['sizes'];
    $uniformSize = $composition['uniform_size'];

    $sizeLabel = match (true) {
        $uniformSize !== null => $uniformSize,
        $sizes !== [] => 'lệch nhau — '.implode(', ', $sizes),
        default => '—',
    };

    $steps = [
        ['Ảnh neo', 'done'],
        ['Environment Library', 'done'],
        ['Storyboard', 'done'],
        ['Clips', 'complete'],
        ['Final Composition', 'active'],
        ['Exports', ''],
    ];

    // Dung danh sach cua `CompositionPlanBuilder` chu khong chep lai mot ban rieng:
    // hai ban se lech nhau, va nguoi dung se chon duoc mot co ma server tu choi.
    // Ti le khung hinh la thu SUY RA tu do phan giai, khong phai mot lua chon rieng:
    // hai o chon doc lap thi chung mau thuan duoc voi nhau, va server se phai chon
    // mot cai de tin.
    $ratioOf = static function (int $w, int $h): string {
        // Euclid thuan, khong dung `gmp_gcd`: may nay khong nap extension gmp, va mot
        // ham khong ton tai o day se giet ca trang.
        [$a, $b] = [$w, $h];

        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        $d = max(1, abs($a));

        return intdiv($w, $d).':'.intdiv($h, $d)
            .($w > $h ? ' (ngang)' : ($w < $h ? ' (dọc)' : ' (vuông)'));
    };

    $sizeChoices = array_map(static function (string $size) use ($ratioOf): array {
        [$w, $h] = array_map('intval', explode('x', $size));

        return [$size, $w.' × '.$h.($w > $h ? ' (ngang)' : ' (dọc)'), $ratioOf($w, $h)];
    }, \App\Video\FinalComposition\CompositionPlanBuilder::SIZES);

    // Mac dinh theo co cua clip nguon khi chung dong nhat; lech nhau thi khong co
    // "co nguon" nao ca, lay ban doc lam mac dinh.
    $uniformKey = $uniformSize === null ? null : str_replace([' ', '×'], ['', 'x'], $uniformSize);
    $defaultSize = in_array($uniformKey, array_column($sizeChoices, 0), true)
        ? $uniformKey
        : '1080x1920';

    // Moc cat cua ban final DANG PHAT, khoa theo render_id. Rong khi chua co
    // final, hoac khi final duoc ghep tu mot bo clip khac bo dang hien.
    $finalCuts = ($latestFinal && $latestFinal['video_url']) ? $latestFinal['cuts'] : [];

    // Khung xem truoc OM THEO ti le cua video, khong ep video vao mot khung sai ti
    // le. Ban final o day la doc 1080x1920; nhet no vao mot khung ngang co dinh thi
    // hoac phai chen vien den, hoac phai cat mat noi dung.
    //
    // Thu tu uu tien: co that cua ban final -> co dong nhat cua clip nguon -> 16:9.
    $previewRatio = match (true) {
        $latestFinal !== null && $latestFinal['width'] > 0 && $latestFinal['height'] > 0
            => [$latestFinal['width'], $latestFinal['height']],
        $clips !== [] && $clips[0]['width'] > 0 && $clips[0]['height'] > 0
            => [$clips[0]['width'], $clips[0]['height']],
        default => [16, 9],
    };

    // Chieu cao toi da cua khung, va be ngang suy ra tu no. Tinh o day chu khong
    // bang `calc()` long nhau trong CSS: mot phep nhan voi mot ti le luu trong bien
    // CSS de viet sai va khi sai thi im lang.
    $previewMaxHeight = 520;
    $previewMaxWidth = (int) round($previewMaxHeight * $previewRatio[0] / $previewRatio[1]);

    // Phim moto: moi clip chi phat phan phim dung, tu `from_ms` trong file va dai `ms`; yacht giu nguyen nhu cu.
    $playlist = array_map(static fn (array $clip) => [
        'src' => $clip['file_url'],
        'ms' => $clip['duration_ms'],
        'poster' => $clip['thumbnail_url'],
        'label' => $sequence($clip['ordinal']).' · '.$clip['title'],
    ] + ($motoFilm ? ['from_ms' => (int) $clip['used_start_ms']] : []), $clips);

    $advancedOptions = [
        ['Tự động cân chỉnh âm lượng', true],
        ['Thêm chuyển cảnh mặc định (Crossfade 0.5s)', true],
        ['Tối ưu hóa kích thước file', false],
        ['Lưu file log FFmpeg', false],
        ['Tạo thumbnail sau khi render', false],
    ];
@endphp
<div class="container-fluid fcomp">
    <header class="fcomp-head">
        <div class="fcomp-head-text">
            <nav class="fcomp-crumb" aria-label="Breadcrumb">
                <a href="{{ route('admin.index') }}"><i class="fas fa-home"></i></a>
                <span>/</span>
                <a href="{{ route('video-projects.index') }}">Video Projects</a>
                <span>/</span>
                <a href="{{ route('video-projects.render-video', $id) }}">{{ $id }}</a>
                <span>/</span>
                <b>Final Composition</b>
            </nav>
            <h2><i class="fas fa-film"></i> Final Composition</h2>
@if($motoFilm)
            <p>Dựng phim từ các clip đã duyệt theo chính sách Phase 11 trong DB: PHP lập kế hoạch theo khung, FFmpeg dựng đúng kế hoạch đó bằng job chạy nền. Không có lượt AI nào.</p>
@else
            <p>Dựng các clip đã dựng xong, thêm chuyển cảnh, nhạc và xuất video bằng FFmpeg.</p>
@endif
        </div>
        <div class="fcomp-head-actions">
            <a class="fcomp-button" href="{{ route('video-projects.render-video', $id) }}"><i class="fas fa-arrow-left"></i> Quay lại Clips</a>
@if($motoFilm && \App\Services\Video\Social\SocialSourceService::enabled() && collect($motoFilm['finals'] ?? [])->contains(static fn (array $final): bool => (bool) ($final['approved'] ?? false)))
            {{-- Phase 12: chi mo khi co ban final da duyet; man Social Publishing tu kiem lai phim va hash. --}}
            <a class="fcomp-button primary" href="{{ route('video-projects.social-publishing', $id) }}" data-fcomp-social><i class="fas fa-share-alt"></i> Social Publishing →</a>
@endif
            <button class="fcomp-button primary" type="button" disabled><i class="far fa-save"></i> Lưu bản nháp</button>
            <button class="fcomp-button success" type="button" disabled><i class="fas fa-cog"></i> Render Final Video</button>
        </div>
    </header>

    <nav class="fcomp-steps" aria-label="Video workflow">
        @foreach($steps as $step)
            <div class="{{ $step[1] }}">
                <span>@if($step[1] === 'complete')<i class="fas fa-check"></i>@else{{ $loop->iteration }}@endif</span>{{ $step[0] }}
            </div>
        @endforeach
    </nav>

    <main class="fcomp-grid">
        <aside class="fcomp-card fcomp-clips">
            <div class="fcomp-card-title">List clip đã dựng xong <span>{{ count($clips) }} clips</span></div>
            @forelse($clips as $clip)
                <div class="fcomp-clip-item" data-fcomp-seek="{{ $loop->index }}"@isset($finalCuts[$clip['render_id']]) data-fcomp-at="{{ $finalCuts[$clip['render_id']] }}"@endisset>
                    <i class="fas fa-grip-vertical"></i>
                    @if($clip['thumbnail_url'])
                        <img src="{{ $clip['thumbnail_url'] }}" alt="{{ $clip['title'] }}">
                    @else
                        <span class="fcomp-thumb-blank"><i class="fas fa-image"></i></span>
                    @endif
                    <div>
                        <b>{{ $sequence($clip['ordinal']) }} - {{ $clip['title'] }}</b>
                        <small>{{ $clock($clip['duration_ms']) }}</small>
                    </div>
                    <i class="fas fa-check-circle"></i>
                </div>
            @empty
                <div class="fcomp-empty">Chưa có clip nào dựng xong. Dựng clip ở màn <a href="{{ route('video-projects.render-video', $id) }}">Clips</a> trước.</div>
            @endforelse
@if($motoFilm)
            <div class="fcomp-note"><i class="fas fa-info-circle"></i> Chỉ hiển thị clip đã duyệt ở màn Clips. Thứ tự theo kế hoạch shot đã duyệt.</div>
            {{-- Pham vi shot cua phim: du kien theo ke hoach shot, da len phim, va tung shot con thieu kem ly do. --}}
            @php $filmScope = $motoFilm['scope'] ?? null; @endphp
            @if($filmScope !== null)
                <div class="fcomp-note" data-fcomp-scope>
                    <i class="fas {{ $filmScope['complete'] ? 'fa-check-circle' : 'fa-exclamation-triangle' }}"></i>
                    <b>Kế hoạch shot: {{ $filmScope['expected'] }} shot · lên phim: {{ $filmScope['used'] }} · còn thiếu: {{ count($filmScope['missing']) }}</b>
                    @foreach($filmScope['missing'] as $missingShot)
                        <div data-fcomp-missing="{{ $missingShot['shot_id'] }}">{{ $missingShot['message'] }}</div>
                    @endforeach
                </div>
            @endif
            {{-- Moi diem chan deu co tren trang: 12 diem dau hien san, phan con lai nam trong mot khoi mo ra duoc, khong cat mat. --}}
            @php $filmBlockers = array_values($motoFilm['blockers'] ?? []); @endphp
            @foreach(array_slice($filmBlockers, 0, 12) as $filmBlocker)
                <div class="fcomp-note" data-fcomp-blocker><i class="fas fa-ban"></i> Chặn: {{ $filmBlocker['message'] }}</div>
            @endforeach
            @if(count($filmBlockers) > 12)
                <details data-fcomp-blockers-more>
                    <summary class="fcomp-note" style="cursor:pointer"><i class="fas fa-ban"></i> Xem thêm {{ count($filmBlockers) - 12 }} điểm chặn</summary>
                    @foreach(array_slice($filmBlockers, 12) as $filmBlocker)
                        <div class="fcomp-note" data-fcomp-blocker><i class="fas fa-ban"></i> Chặn: {{ $filmBlocker['message'] }}</div>
                    @endforeach
                </details>
            @endif
            @foreach($motoFilm['warnings'] ?? [] as $filmWarning)
                <div class="fcomp-note" data-fcomp-warning><i class="fas fa-exclamation-circle"></i> Cảnh báo: {{ $filmWarning['message'] }}</div>
            @endforeach
            {{-- Phuong an dung: cac quyet dinh phim duoc dung theo. Chi co o du an ma ban phat hanh khai action phuong an dung; du an khac khong co khoi nay va dung nhu truoc. --}}
            @php $filmEdit = $motoFilm['edit'] ?? null; @endphp
            @if($filmEdit !== null)
                <div class="fcomp-note" data-fcomp-edit-plan>
                    <i class="fas fa-list-ol"></i>
                    <b>Phương án dựng: {{ $filmEdit['origin'] === \App\Video\Moto\Film\MotoFilmEditPlan::ORIGIN_DEFAULT ? 'mặc định (code lập từ kế hoạch shot và clip đã duyệt)' : $filmEdit['origin'] }}</b>
                    @forelse($filmEdit['segments'] as $filmSegment)
                        <div data-fcomp-segment="{{ $filmSegment['id'] }}">{{ $filmSegment['id'] }} · {{ $filmSegment['shot_id'] }} · take {{ substr($filmSegment['render_id'], 0, 8) }} · {{ $filmSegment['in_ms'] }}–{{ $filmSegment['out_ms'] }} ms · {{ $filmSegment['transition'] ?? 'đầu phim' }}</div>
                    @empty
                        <div>Chưa lập được phương án dựng: kế hoạch phim còn bị chặn.</div>
                    @endforelse
                    @if($filmEdit['segments'] !== [])
                        <div>Tiếng: tiếng của clip · Màu: giữ nguyên · Chữ: {{ $filmEdit['cards'] }} card của kịch bản</div>
                        <div>Bấm Dựng là xác nhận đúng phương án này.</div>
                    @endif
                    @foreach($filmEdit['problems'] as $editProblem)
                        <div data-fcomp-edit-problem><i class="fas fa-ban"></i> {{ $editProblem }}</div>
                    @endforeach
                </div>
            @endif
@else
            <div class="fcomp-note"><i class="fas fa-info-circle"></i> Chỉ hiển thị clip đã dựng xong. Thứ tự lấy theo thứ tự scene của bản kế hoạch mới nhất.</div>
@endif
        </aside>

        <section class="fcomp-center">
            <div class="fcomp-card fcomp-preview">
                <div class="fcomp-card-title">Xem trước Final Video</div>
                <div class="fcomp-player" style="--fcomp-ar:{{ $previewRatio[0] }}/{{ $previewRatio[1] }};--fcomp-w:{{ $previewMaxWidth }}px">
                    @if($latestFinal && $latestFinal['video_url'])
                        {{-- `data-fcomp-final` de JS biet day la mot the DUY NHAT, khong
                             phai cap deck luan phien: playhead bam theo chinh no. --}}
                        <video data-fcomp-final src="{{ $latestFinal['video_url'] }}" controls preload="metadata" playsinline></video>
                        <span class="fcomp-draft">{{ $latestFinal['status'] }}</span>
                    @elseif($clips !== [])
                        {{-- Chua co ban final thi phat CHINH cac clip, noi duoi nhau theo
                             thu tu timeline.

                             HAI the video luan phien chu khong phai mot: doi `src` tren
                             mot the buoc trinh duyet tai lai tu dau, va do la khoang den
                             giua hai canh. The con lai nap san clip ke trong luc the kia
                             dang chay. --}}
                        <video class="fcomp-deck on" data-fcomp-deck="0"
                            src="{{ $clips[0]['file_url'] }}" data-clip="0"
                            @if($clips[0]['thumbnail_url']) poster="{{ $clips[0]['thumbnail_url'] }}" @endif
                            preload="auto" playsinline></video>
                        <video class="fcomp-deck" data-fcomp-deck="1" preload="auto" playsinline></video>

                        <span class="fcomp-draft" data-fcomp-now>{{ $sequence(1) }} · {{ $clips[0]['title'] }}</span>

                        <div class="fcomp-transport" data-fcomp-transport>
                            <button type="button" data-fcomp-toggle aria-label="Phát"><i class="fas fa-play"></i></button>
                            <b data-fcomp-time>00:00 / {{ $clock($totalMs) }}</b>
                            <div class="fcomp-scrub" data-fcomp-scrub><i></i></div>
                            <button type="button" data-fcomp-full aria-label="Toàn màn hình"><i class="fas fa-expand"></i></button>
                        </div>

                        <script type="application/json" data-fcomp-playlist>@json($playlist, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)</script>
                    @else
                        <div class="fcomp-player-empty">
                            <i class="fas fa-film"></i>
                            <b>Chưa có clip nào dựng xong</b>
                        </div>
                    @endif
                </div>
@if($motoFilm)
                <div class="fcomp-note"><i class="fas fa-info-circle"></i> Khi chưa có bản final, khung này chỉ phát nối các clip đã duyệt: không có dip, card và tiếng như file FFmpeg dựng ra. Chỉ duyệt file final đã dựng và đã kiểm.</div>
@endif
            </div>

            <div class="fcomp-card fcomp-timeline-panel">
                <div class="fcomp-card-title">Timeline <span>{{ $clock($totalMs) }}</span></div>
                <div class="fcomp-tools">
                    <button type="button" disabled><i class="fas fa-plus"></i> Thêm clip</button>
                    <button type="button" disabled><i class="fas fa-plus"></i> Thêm nhạc</button>
                    <button type="button" disabled><i class="fas fa-plus"></i> Thêm thuyết minh</button>
                    <button type="button" disabled><i class="fas fa-plus"></i> Thêm SFX</button>
                    <span class="fcomp-tools-gap"></span>
                    <label><i class="fas fa-search"></i> Zoom <input type="range" value="65" disabled></label>
                    <button type="button" disabled>Fit</button>
                    <button class="fcomp-icon-button" type="button" aria-label="Mở rộng timeline" disabled><i class="fas fa-expand-arrows-alt"></i></button>
                </div>
                <div class="fcomp-timeline">
                    <div class="fcomp-track-labels">
                        <span></span>
                        <span><i class="fas fa-film"></i> Video</span>
                        <span><i class="fas fa-music"></i> BGM</span>
                        <span><i class="fas fa-microphone"></i> Voiceover</span>
                        <span><i class="fas fa-wave-square"></i> SFX</span>
                    </div>
                    <div class="fcomp-lanes">
                        <div class="fcomp-ruler">
                            @foreach($ticks as $tick)
                                <span>{{ $tick }}</span>
                            @endforeach
                        </div>
                        <div class="fcomp-track fcomp-video-track">
                            @forelse($clips as $clip)
                                <div class="fcomp-shot" data-fcomp-seek="{{ $loop->index }}"@isset($finalCuts[$clip['render_id']]) data-fcomp-at="{{ $finalCuts[$clip['render_id']] }}"@endisset style="flex:{{ $clip['duration_ms'] }}@if($clip['thumbnail_url']);background-image:url('{{ $clip['thumbnail_url'] }}')@endif" title="{{ $clip['title'] }}">
                                    <b>{{ $sequence($clip['ordinal']) }}</b>
                                    <small>{{ $clock($clip['duration_ms']) }}</small>
                                </div>
                            @empty
                                <div class="fcomp-lane-empty">chưa có clip</div>
                            @endforelse
                        </div>
                        <div class="fcomp-track fcomp-bgm"><em class="fcomp-lane-empty">chưa nối nhạc nền</em></div>
                        <div class="fcomp-track fcomp-voice"><em class="fcomp-lane-empty">chưa nối thuyết minh</em></div>
                        <div class="fcomp-track fcomp-sfx"><em class="fcomp-lane-empty">chưa nối SFX</em></div>
                        <div class="fcomp-playhead" style="left:0"><b>00:00</b></div>
                    </div>
                </div>
            </div>
        </section>

        <aside class="fcomp-right">
            <section class="fcomp-card fcomp-project">
                <div class="fcomp-card-title">Thông tin dự án</div>
                <dl>
                    <dt>Project</dt><dd title="{{ $id }}">{{ $id }}</dd>
                    <dt>Title</dt><dd title="{{ $project->title }}">{{ $project->title }}</dd>
                    <dt>Duration (ước tính)</dt><dd>{{ $clock($totalMs) }}</dd>
                    <dt>Số clip</dt><dd>{{ count($clips) }}</dd>
                    <dt>Khung hình</dt><dd title="{{ $sizeLabel }}">{{ $sizeLabel }}</dd>
                    <dt>Trạng thái</dt><dd><span class="fcomp-badge"><i class="far fa-file"></i> {{ $latestFinal['status'] ?? 'Draft' }}</span></dd>
                </dl>
            </section>

            <form class="fcomp-card fcomp-settings" method="POST" action="{{ route('video-projects.final-render', $id) }}">
                @csrf
                <div class="fcomp-card-title">Thiết lập xuất video</div>
@if($motoFilm)
                @php $output = $motoFilm['output']; @endphp
                {{-- Phim moto lay thiet lap tu chinh sach Phase 11 trong DB; cac o chi de xem. --}}
                <label><span>Độ phân giải</span><select disabled><option>{{ $output ? $output['width'].' × '.$output['height'] : '—' }}</option></select></label>
                <label><span>Frame rate (FPS)</span><select disabled><option>{{ $output ? $output['fps'].' fps' : '—' }}</option></select></label>
                <label><span>Video codec</span><select disabled><option>H.264 (libx264)</option></select></label>
                <label><span>Audio codec</span><select disabled><option>AAC 48 kHz stereo — chỉ tiếng của clip</option></select></label>
                <label><span>Chất lượng (CRF)</span><select disabled><option>{{ $output['crf'] ?? '—' }}</option></select></label>
                <small>Thiết lập theo chính sách Phase 11{{ $output ? ' ('.$output['id'].')' : '' }}; chuyển cảnh, dip và card theo kế hoạch phim, không chỉnh ở đây.</small>
                <input type="hidden" name="expected_hash" value="{{ $motoFilm['hash'] ?? '' }}">
                <input type="hidden" name="operation_id" value="{{ $motoFilm['operation_id'] }}">
                {{-- Du an dung theo phuong an dung: moi muc dich co mot phuong an rieng, trang gui ma bam cua ca hai va server doi chieu cai cua nut duoc bam. Bam Dung la xac nhan dung phuong an do. --}}
                @foreach(($motoFilm['edit']['hashes'] ?? []) as $editPurpose => $editHash)
                    <input type="hidden" name="expected_edit_hash[{{ $editPurpose }}]" value="{{ $editHash }}">
                @endforeach
                {{-- Hai muc dich dung: ban final phai du moi shot cua ke hoach shot va duyet xuat ban duoc; ban dung thu ghep phan clip da duyet va khong bao gio duyet xuat ban. Server quyet dinh, nut chi gui muc dich. --}}
                <button class="fcomp-render" type="submit" name="purpose" value="{{ \App\Video\Moto\Film\MotoFilmScope::FINAL }}" @disabled(($composition['blocked'] ?? null) !== null || ($motoFilm['final_problem'] ?? null) !== null)><i class="fas fa-cog"></i> Dựng final</button>
                <button class="fcomp-render" type="submit" name="purpose" value="{{ \App\Video\Moto\Film\MotoFilmScope::PREVIEW }}" @disabled(($composition['blocked'] ?? null) !== null)><i class="fas fa-eye"></i> Dựng thử</button>
                <p>{{ $composition['blocked'] ?? $motoFilm['final_problem'] ?? (\App\Video\Queue\VideoQueues::enabled() ? 'Lượt dựng vào hàng đợi FFmpeg và chạy nền; đóng tab không làm dừng nó.' : 'Máy này không bật hàng đợi: phim được dựng ngay trong lượt bấm — đừng đóng tab cho tới khi trang tải lại.') }}</p>
                <small>Bản dựng thử ghép các clip đã duyệt để xem trước và không duyệt xuất bản được; chỉ bản final đủ shot mới duyệt được.</small>
@else

                <label>
                    <span>Độ phân giải</span>
                    <select name="size" data-fcomp-size @disabled($clips === [])>
                        @foreach($sizeChoices as $size)
                            <option value="{{ $size[0] }}" data-ratio="{{ $size[2] }}" @selected($size[0] === $defaultSize)>{{ $size[1] }}</option>
                        @endforeach
                    </select>
                </label>
                {{-- Tro, vi day la he qua cua o tren chu khong phai mot lua chon rieng.
                     Van hien ra vi nguoi dung can THAY minh dang xuat theo ti le nao. --}}
                <label>
                    <span>Tỉ lệ khung hình</span>
                    <select disabled data-fcomp-ratio>
                        <option>{{ collect($sizeChoices)->firstWhere(0, $defaultSize)[2] ?? '16:9 (ngang)' }}</option>
                    </select>
                </label>
                <label>
                    <span>Frame rate (FPS)</span>
                    <select name="fps" @disabled($clips === [])>
                        @foreach([24, 25, 30] as $fps)
                            <option value="{{ $fps }}" @selected($fps === 24)>{{ $fps }} fps</option>
                        @endforeach
                    </select>
                </label>
                {{-- Hai o nay chi co dung mot lua chon duoc ho tro, nen chung tro. --}}
                <label><span>Video codec</span><select disabled><option>H.264 (libx264)</option></select></label>
                <label><span>Audio codec</span><select disabled><option>AAC</option></select></label>

                <label>
                    <span>Chất lượng (CRF)</span>
                    <span class="fcomp-crf">
                        <input type="range" name="crf" min="16" max="28" value="18" @disabled($clips === []) oninput="this.nextElementSibling.textContent=this.value">
                        <b>18</b>
                    </span>
                </label>
                <small>Giá trị thấp hơn = chất lượng cao hơn (khuyến nghị: 16-20)</small>

                <details open>
                    <summary>Tùy chọn nâng cao <i class="fas fa-chevron-up"></i></summary>
                    <label><input type="checkbox" name="crossfade" value="1" @disabled($clips === [])> Chuyển cảnh mờ giữa các clip (0,5 giây)</label>
                    @foreach($advancedOptions as $option)
                        <label><input type="checkbox" disabled @checked($option[1])> {{ $option[0] }}</label>
                    @endforeach
                </details>

                <button class="fcomp-render" type="submit" @disabled($clips === [] || ($composition['blocked'] ?? null) !== null)><i class="fas fa-cog"></i> Render Final Video</button>
                <p>
                    @if(($composition['blocked'] ?? null) !== null)
                        {{ $composition['blocked'] }}
                    @elseif($clips === [])
                        Chưa có clip nào dựng xong nên chưa ghép được.
                    @else
                        Ghép chạy đồng bộ ngay trong request, chưa qua hàng đợi — {{ count($clips) }} clip,
                        {{ $clock($totalMs) }}. Đừng đóng tab khi đang chạy.
                    @endif
                </p>
@endif
            </form>
        </aside>

        <section class="fcomp-card fcomp-history">
            <div class="fcomp-card-title">Lịch sử render</div>
            <div class="fcomp-history-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Thời gian</th>
                            <th>Tên file</th>
                            <th>Độ phân giải</th>
                            <th>Thời lượng</th>
                            <th>Trạng thái</th>
                            <th>Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($finals as $final)
                            <tr>
                                <td>{{ $loop->iteration }}</td>
                                <td>{{ $final['created_at'] ?? '—' }}</td>
                                <td>{{ $final['file_name'] ?? '—' }}</td>
                                <td>{{ $final['width'] && $final['height'] ? $final['width'].' × '.$final['height'] : '—' }}</td>
                                <td>{{ $clock($final['duration_seconds'] * 1000) }}</td>
                                <td><span class="fcomp-status">{{ $final['status'] }}</span></td>
                                <td class="fcomp-actions">
                                    @if($final['video_url'])
                                        <a class="fcomp-download" href="{{ $final['video_url'] }}" download><i class="fas fa-download"></i> Tải xuống</a>
                                        {{-- Du an co QC: moi ban co file deu chon duoc de xem va kiem QC. Trinh phat, bao cao, finding va cac form deu ve dung ban duoc chon. --}}
                                        @if(($motoFilm['qc'] ?? null) !== null && ($motoFilm['finals'][$final['id']]['view_url'] ?? null) !== null)
                                            @if($motoFilm['finals'][$final['id']]['viewed'] ?? false)
                                                <span class="fcomp-status" data-fcomp-viewed="{{ $final['id'] }}"><i class="fas fa-eye"></i> Đang xem và kiểm QC bản này</span>
                                            @else
                                                <a class="fcomp-download" href="{{ $motoFilm['finals'][$final['id']]['view_url'] }}" data-fcomp-view="{{ $final['id'] }}"><i class="fas fa-eye"></i> Xem và kiểm QC bản này</a>
                                            @endif
                                        @endif
                                        @foreach(($motoFilm['finals'][$final['id']]['documents'] ?? []) as $filmDocument)
                                            <a class="fcomp-download" href="{{ route('video-projects.final-document', [$id, $final['id'], $filmDocument]) }}" data-fcomp-document="{{ $filmDocument }}"><i class="fas fa-file-code"></i> {{ ['timeline' => 'Timeline', 'edit-decisions' => 'Quyết định dựng', 'approval' => 'Quyết định duyệt'][$filmDocument] }}</a>
                                        @endforeach
@if($motoFilm['finals'][$final['id']]['can_approve'] ?? false)
                                        <form method="POST" action="{{ route('video-projects.final-approve', [$id, $final['id']]) }}">
                                            @csrf
                                            <input type="hidden" name="expected_plan_hash" value="{{ $motoFilm['finals'][$final['id']]['film_plan_hash'] }}">
                                            <input type="hidden" name="expected_sha256" value="{{ $motoFilm['finals'][$final['id']]['sha256'] }}">
                                            <input type="hidden" name="operation_id" value="{{ \Illuminate\Support\Str::uuid() }}">
                                            <button class="fcomp-download" type="submit"><i class="fas fa-check"></i> Duyệt final</button>
                                        </form>
@elseif(($motoFilm['finals'][$final['id']]['approval_problem'] ?? null) !== null)
                                        <span class="fcomp-empty-cell" data-fcomp-not-approvable>{{ $motoFilm['finals'][$final['id']]['approval_problem'] }}</span>
@endif
                                    @else
                                        <span class="fcomp-empty-cell">không có file</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td class="fcomp-empty-cell" colspan="7">Chưa có lần render nào.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
{{-- QC cua ban final moi nhat co file. Chi co o du an ma ban phat hanh khai action QC; du an khac khong co khoi nay va duyet nhu truoc.
     Code do tren file va dem; nguoi review ket luan noi dung va xu ly finding. Hai ket qua khong gop thanh mot chu "dat". --}}
@php
    $filmQc = $motoFilm['qc'] ?? null;
    $qcVerdicts = ['pass' => 'Đạt', 'fail' => 'Không đạt', 'not_checked' => 'Chưa kiểm'];
    $qcStatuses = ['new' => 'mới', 'fixed' => 'đã sửa', 'rechecked' => 'đã kiểm lại', 'accepted' => 'chấp nhận kèm lý do', 'false_positive' => 'báo nhầm'];
    $qcSeverities = ['info' => 'thông tin', 'minor' => 'nhẹ', 'major' => 'nặng', 'critical' => 'nghiêm trọng'];
@endphp
@if($filmQc !== null)
        <section class="fcomp-card fcomp-history" data-fcomp-qc>
            <div class="fcomp-card-title">QC bản dựng @if($filmQc['final'])<span data-fcomp-qc-final="{{ $filmQc['final']['id'] }}">revision {{ $filmQc['final']['revision'] }} · {{ collect($finals)->firstWhere('id', $filmQc['final']['id'])['status'] ?? '' }}</span>@endif</div>
            @if($filmQc['final'])
                <div class="fcomp-note"><i class="fas fa-info-circle"></i> Trình phát phía trên, báo cáo, finding và các thao tác dưới đây đều là của revision {{ $filmQc['final']['revision'] }}. Muốn kiểm bản khác, bấm "Xem và kiểm QC bản này" ở dòng của bản đó trong Lịch sử render.</div>
            @endif
            @if($filmQc['problem'])
                <div class="fcomp-note"><i class="fas fa-ban"></i> {{ $filmQc['problem'] }}</div>
            @elseif($filmQc['final'] === null)
                <div class="fcomp-note"><i class="fas fa-info-circle"></i> Chưa có bản dựng nào có file để kiểm QC.</div>
            @else
                @php $qcReport = $filmQc['report']; $qcFinal = $filmQc['final']['id']; @endphp
                <form method="POST" action="{{ route('video-projects.final-qc', [$id, $qcFinal]) }}" class="fcomp-note">
                    @csrf
                    <button class="fcomp-download" type="submit"><i class="fas fa-stethoscope"></i> {{ $qcReport ? 'Kiểm QC lại' : 'Kiểm QC' }}</button>
                    Đo file bằng FFmpeg trên máy này; không gọi AI, không phát sinh phí.
                </form>
                @if($filmQc['changes_requested'])
                    <div class="fcomp-note" data-fcomp-qc-changes><i class="fas fa-undo"></i> Đã yêu cầu sửa lúc {{ $filmQc['changes_requested']['at'] }}: {{ $filmQc['changes_requested']['reason'] }}</div>
                @endif
                @if($filmQc['approval_problem'])
                    <div class="fcomp-note" data-fcomp-qc-problem><i class="fas fa-ban"></i> {{ $filmQc['approval_problem'] }}</div>
                @endif
                @if($qcReport === null)
                    <div class="fcomp-note"><i class="fas fa-info-circle"></i> Bản này chưa có báo cáo QC.</div>
                @else
                    <div class="fcomp-note" data-fcomp-qc-technical>
                        <b>QC kỹ thuật: {{ ['pass' => 'đạt', 'fail' => 'không đạt', 'error' => 'chưa đo được'][$qcReport['technical']['status']] }}</b>
                        (file {{ substr($qcReport['final']['artifact_sha256'], 0, 12) }}…)
                        <a class="fcomp-download" href="{{ route('video-projects.final-document', [$id, $qcFinal, 'qc-report']) }}" data-fcomp-document="qc-report"><i class="fas fa-file-code"></i> Tải báo cáo QC</a>
                        @foreach($qcReport['technical']['checks'] as $qcCheck)
                            <div data-fcomp-qc-check="{{ $qcCheck['code'] }}">[{{ $qcCheck['status'] }}] {{ $qcCheck['detail'] }}</div>
                        @endforeach
                    </div>

                    <form method="POST" action="{{ route('video-projects.final-qc-review', [$id, $qcFinal]) }}" class="fcomp-note" data-fcomp-qc-review>
                        @csrf
                        <input type="hidden" name="operation_id" value="{{ \Illuminate\Support\Str::uuid() }}">
                        <b>QC nội dung: {{ ['not_reviewed' => 'chưa review', 'partly_reviewed' => 'review một phần', 'reviewed' => 'đã review đủ mục bắt buộc'][$qcReport['content']['status']] }}</b>
                        — xem file rồi kết luận từng mục; mục có dấu * là bắt buộc. Mục để "Chưa kiểm" giữ kết luận đang có.
                        @foreach($filmQc['areas'] as $qcArea)
                            <div data-fcomp-qc-area="{{ $qcArea['id'] }}">
                                {{ $qcArea['label'] }}{{ $qcArea['required'] ? ' *' : '' }} — hiện: {{ $qcVerdicts[$qcArea['verdict']] }}
                                <select name="verdicts[{{ $qcArea['id'] }}]">
                                    @foreach($qcVerdicts as $qcValue => $qcLabel)
                                        <option value="{{ $qcValue }}" @selected($qcValue === 'not_checked')>{{ $qcLabel }}</option>
                                    @endforeach
                                </select>
                                <input type="text" name="notes[{{ $qcArea['id'] }}]" maxlength="2000" placeholder="Ghi chú">
                            </div>
                        @endforeach
                        <button class="fcomp-download" type="submit"><i class="fas fa-clipboard-check"></i> Ghi review</button>
                    </form>

                    <div class="fcomp-note" data-fcomp-qc-findings>
                        <b>Finding: {{ count($qcReport['findings']) }}</b> — mức {{ implode(', ', array_map(fn ($severity) => $qcSeverities[$severity] ?? $severity, $filmQc['blocking'])) }} chặn duyệt khi chưa xử lý.
                        @forelse($qcReport['findings'] as $qcFinding)
                            <div data-fcomp-qc-finding="{{ $qcFinding['id'] }}">
                                {{ $qcFinding['id'] }} · {{ $qcSeverities[$qcFinding['severity']] ?? $qcFinding['severity'] }} · {{ $qcStatuses[$qcFinding['status']] ?? $qcFinding['status'] }}
                                · {{ $qcFinding['raised_by']['kind'] === 'code' ? 'máy đo' : 'người review' }}@if($qcFinding['shots'] !== []) · {{ implode(', ', $qcFinding['shots']) }}@endif
                                — {{ $qcFinding['description'] }}
                                @if($qcFinding['suggestion']) Hướng xử lý: {{ $qcFinding['suggestion'] }}@endif
                                @if($qcFinding['resolution']) Lý do: {{ $qcFinding['resolution']['reason'] }}@endif
                                <form method="POST" action="{{ route('video-projects.final-qc-settle', [$id, $qcFinal, $qcFinding['id']]) }}">
                                    @csrf
                                    <input type="hidden" name="operation_id" value="{{ \Illuminate\Support\Str::uuid() }}">
                                    <select name="status">
                                        @foreach($filmQc['statuses'] as $qcStatus)
                                            <option value="{{ $qcStatus }}">{{ $qcStatuses[$qcStatus] ?? $qcStatus }}</option>
                                        @endforeach
                                    </select>
                                    <input type="text" name="reason" maxlength="2000" placeholder="Lý do" required>
                                    <button class="fcomp-download" type="submit">Ghi xử lý</button>
                                </form>
                            </div>
                        @empty
                            <div>Chưa có finding nào.</div>
                        @endforelse
                    </div>

                    <form method="POST" action="{{ route('video-projects.final-qc-finding', [$id, $qcFinal]) }}" class="fcomp-note" data-fcomp-qc-raise>
                        @csrf
                        <input type="hidden" name="operation_id" value="{{ \Illuminate\Support\Str::uuid() }}">
                        <b>Nêu finding</b>
                        <select name="severity">
                            @foreach($filmQc['severities'] as $qcSeverity)
                                <option value="{{ $qcSeverity }}">{{ $qcSeverities[$qcSeverity] ?? $qcSeverity }}</option>
                            @endforeach
                        </select>
                        <select name="area">
                            <option value="">— mục review —</option>
                            @foreach($filmQc['areas'] as $qcArea)
                                <option value="{{ $qcArea['id'] }}">{{ $qcArea['label'] }}</option>
                            @endforeach
                        </select>
                        <input type="number" name="start_s" min="0" step="0.001" placeholder="Từ giây">
                        <input type="number" name="end_s" min="0" step="0.001" placeholder="Đến giây">
                        <input type="text" name="description" maxlength="2000" placeholder="Mô tả vấn đề" required>
                        <input type="text" name="suggestion" maxlength="2000" placeholder="Hướng xử lý đề xuất">
                        <button class="fcomp-download" type="submit"><i class="fas fa-flag"></i> Ghi finding</button>
                    </form>

                    <form method="POST" action="{{ route('video-projects.final-request-changes', [$id, $qcFinal]) }}" class="fcomp-note" data-fcomp-qc-request>
                        @csrf
                        <input type="hidden" name="operation_id" value="{{ \Illuminate\Support\Str::uuid() }}">
                        <b>Yêu cầu sửa</b> — bản này ở lại lịch sử và không duyệt được nữa; sửa xong thì dựng bản mới.
                        <input type="text" name="reason" maxlength="2000" placeholder="Lý do yêu cầu sửa" required>
                        <button class="fcomp-download" type="submit"><i class="fas fa-undo"></i> Yêu cầu sửa</button>
                    </form>
                @endif
            @endif
        </section>
@endif
    </main>
</div>
@endsection

@section('script')
<script src="{{ asset('assets/js/final-composition.js') }}?v={{ filemtime(public_path('assets/js/final-composition.js')) }}"></script>
@if($motoFilm['active'] ?? null)
<script>
    // Phim moto dang dung bang job chay nen: hoi trang thai, doi thi tai lai trang. Chi doc, khong gui gi.
    (function () {
        const url = @json(route('video-projects.final-status', [$id, $motoFilm['active']['id']]));
        const was = @json($motoFilm['active']['status']);
        const tick = () => fetch(url, { headers: { Accept: 'application/json' } })
            .then((response) => response.ok ? response.json() : null)
            .then((row) => { if (row && (row.status !== was || row.stopped)) { window.location.reload(); } else { setTimeout(tick, 5000); } })
            .catch(() => setTimeout(tick, 15000));
        setTimeout(tick, 5000);
    })();
</script>
@endif
@endsection
