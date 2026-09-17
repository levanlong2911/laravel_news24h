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
        ['Scenes', 'done'],
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

    $playlist = array_map(static fn (array $clip) => [
        'src' => $clip['file_url'],
        'ms' => $clip['duration_ms'],
        'poster' => $clip['thumbnail_url'],
        'label' => $sequence($clip['ordinal']).' · '.$clip['title'],
    ], $clips);

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
            <p>Dựng các clip đã dựng xong, thêm chuyển cảnh, nhạc và xuất video bằng FFmpeg.</p>
        </div>
        <div class="fcomp-head-actions">
            <a class="fcomp-button" href="{{ route('video-projects.render-video', $id) }}"><i class="fas fa-arrow-left"></i> Quay lại Clips</a>
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
            <div class="fcomp-note"><i class="fas fa-info-circle"></i> Chỉ hiển thị clip đã dựng xong. Thứ tự lấy theo thứ tự scene của bản kế hoạch mới nhất.</div>
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

                <button class="fcomp-render" type="submit" @disabled($clips === [])><i class="fas fa-cog"></i> Render Final Video</button>
                <p>
                    @if($clips === [])
                        Chưa có clip nào dựng xong nên chưa ghép được.
                    @else
                        Ghép chạy đồng bộ ngay trong request, chưa qua hàng đợi — {{ count($clips) }} clip,
                        {{ $clock($totalMs) }}. Đừng đóng tab khi đang chạy.
                    @endif
                </p>
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
    </main>
</div>
@endsection

@section('script')
<script src="{{ asset('assets/js/final-composition.js') }}?v={{ filemtime(public_path('assets/js/final-composition.js')) }}"></script>
@endsection
