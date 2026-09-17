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

    $exportFields = [
        ['Độ phân giải', array_values(array_filter([
            $uniformSize === null ? null : $uniformSize.' (theo clip nguồn)',
            '1920 × 1080 (Full HD)',
            '1280 × 720 (HD)',
            '1080 × 1920 (Vertical)',
        ]))],
        ['Tỉ lệ khung hình', ['16:9 (Landscape)', '9:16 (Portrait)', '1:1 (Square)']],
        ['Frame rate (FPS)', ['24 fps', '25 fps', '30 fps']],
        ['Video codec', ['H.264 (libx264)', 'H.265 (libx265)']],
        ['Audio codec', ['AAC', 'MP3']],
    ];

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
                <div class="fcomp-clip-item" data-fcomp-seek="{{ $loop->index }}">
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
                <div class="fcomp-player">
                    @if($latestFinal && $latestFinal['video_url'])
                        <video src="{{ $latestFinal['video_url'] }}" controls preload="metadata" playsinline></video>
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
                                <div class="fcomp-shot" data-fcomp-seek="{{ $loop->index }}" style="flex:{{ $clip['duration_ms'] }}@if($clip['thumbnail_url']);background-image:url('{{ $clip['thumbnail_url'] }}')@endif" title="{{ $clip['title'] }}">
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

            <section class="fcomp-card fcomp-settings">
                <div class="fcomp-card-title">Thiết lập xuất video</div>
                @foreach($exportFields as $field)
                    <label>
                        <span>{{ $field[0] }}</span>
                        <select disabled>
                            @foreach($field[1] as $option)
                                <option>{{ $option }}</option>
                            @endforeach
                        </select>
                    </label>
                @endforeach
                <label>
                    <span>Chất lượng (CRF)</span>
                    <span class="fcomp-crf"><input type="range" min="12" max="32" value="18" disabled><b>18</b></span>
                </label>
                <small>Giá trị thấp hơn = chất lượng cao hơn (khuyến nghị: 16-20)</small>

                <details open>
                    <summary>Tùy chọn nâng cao <i class="fas fa-chevron-up"></i></summary>
                    @foreach($advancedOptions as $option)
                        <label><input type="checkbox" disabled @checked($option[1])> {{ $option[0] }}</label>
                    @endforeach
                </details>

                <button class="fcomp-render" type="button" disabled><i class="fas fa-cog"></i> Render Final Video</button>
                <p>Chưa nối đường render. Các ô trên là thiết lập dự kiến, chưa lưu được.</p>
            </section>
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
