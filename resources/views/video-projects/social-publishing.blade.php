@extends('layouts.base', ['title' => 'Social Publishing'])
@section('title', 'Social Publishing')

@section('css')
<link rel="stylesheet" href="{{ asset('assets/css/final-composition.css') }}?v={{ filemtime(public_path('assets/css/final-composition.css')) }}">
<link rel="stylesheet" href="{{ asset('assets/css/social-publishing.css') }}?v={{ filemtime(public_path('assets/css/social-publishing.css')) }}">
@endsection

@section('content')
@php
    $can = $social['abilities'];
    $flags = $social['flags'];
    $film = $social['film'];
    $sel = $social['selected'];
    $platforms = config('video.social.platforms');
    $languages = config('video.social.languages');
    $uuid = static fn (): string => (string) \Illuminate\Support\Str::uuid();
    $clock = static fn (?int $ms): string => $ms === null ? '—' : sprintf('%02d:%02d.%03d', intdiv($ms, 60000), intdiv($ms % 60000, 1000), $ms % 1000);
    $mmss = static fn (?int $ms): string => $ms === null ? '—' : sprintf('%02d:%02d', intdiv($ms, 60000), intdiv($ms % 60000, 1000));
    // Thoi diem luu UTC, hien theo mui gio cua kenh.
    $when = static fn ($time, ?string $zone): string => $time === null ? '—' : \Illuminate\Support\Carbon::parse($time)->timezone($zone ?: 'UTC')->format('Y-m-d H:i').' ('.($zone ?: 'UTC').')';
    $ratio = static function (?int $width, ?int $height): string {
        if (! $width || ! $height) {
            return '—';
        }

        foreach (['9:16' => 9 / 16, '16:9' => 16 / 9, '1:1' => 1.0, '4:5' => 0.8, '4:3' => 4 / 3, '3:4' => 0.75] as $label => $value) {
            if (abs($width / $height - $value) / $value < 0.02) {
                return $label;
            }
        }

        [$a, $b] = [$width, $height];

        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return intdiv($width, $a).':'.intdiv($height, $a);
    };
    // Hashtag trong noi dung duoc to mau; moi thu khac duoc escape truoc.
    $highlight = static fn (string $text): string => (string) preg_replace('/(^|\s)(#[\p{L}\p{N}_]+)/u', '$1<span class="sp-hashtag">$2</span>', e($text));
    $platformIcon = static fn (?string $platform): string => match ($platform) {
        'facebook' => 'fab fa-facebook sp-fab-facebook',
        'youtube' => 'fab fa-youtube sp-fab-youtube',
        default => 'fas fa-globe',
    };
    $statusLabel = [
        'draft' => ['Bản nháp', ''], 'approved' => ['Đã duyệt', 'ok'], 'revoked' => ['Đã thu hồi duyệt', 'bad'],
        'manual_reported' => ['Đã đăng — xác nhận thủ công', 'info'], 'queued' => ['Chờ gửi', ''], 'uploading' => ['Đang tải lên', 'warn'],
        'processing' => ['Nền tảng đang xử lý', 'warn'], 'scheduled' => ['Nền tảng giữ lịch', 'info'], 'published' => ['Đã đăng — API xác nhận', 'ok'],
        'failed' => ['Lỗi', 'bad'], 'unknown' => ['Chưa rõ kết quả', 'bad'], 'cancelled' => ['Đã hủy', ''],
    ];
    $tag = static function (string $status, bool $byPerson = false) use ($statusLabel): string {
        [$label, $class] = $statusLabel[$status] ?? [$status, ''];

        if ($status === 'published' && $byPerson) {
            [$label, $class] = ['Đã đăng — xác nhận thủ công', 'info'];
        }

        return '<span class="social-tag '.$class.'" data-social-status="'.e($status).'">'.e($label).'</span>';
    };
    $revisionLabel = ['draft' => 'Nháp', 'approved' => 'Đã duyệt', 'revoked' => 'Thu hồi'];
    $limit = static fn (array $field): string => implode(', ', array_filter([
        $field['max_chars'] !== null ? 'tối đa '.$field['max_chars'].' ký tự' : null,
        $field['max_bytes'] !== null ? 'tối đa '.$field['max_bytes'].' byte' : null,
        $field['forbidden'] !== [] ? 'không dùng '.implode(' ', $field['forbidden']) : null,
    ])) ?: 'tài liệu nền tảng không nêu giới hạn';

    // Thanh chon: kenh, dinh dang, ngon ngu cua goi dang mo; goi co san duoc tim theo bo ba nay.
    $activeChannels = array_values(array_filter($social['channels'], static fn (array $channel): bool => $channel['status'] === 'active'));
    $packageMap = [];

    foreach ($social['packages'] as $row) {
        $packageMap[$row['channel_id'].'|'.$row['format'].'|'.$row['language']] = $row['id'];
    }

    $current = ['channel' => $sel['channel']['id'] ?? ($activeChannels[0]['id'] ?? null), 'format' => $sel['format'] ?? null, 'language' => $sel['language'] ?? null];
    $currentChannel = collect($social['channels'])->firstWhere('id', $current['channel']);
    $currentPlatform = $currentChannel['platform'] ?? null;

    if ($current['format'] === null) {
        foreach ($social['formats'] as $key => $rules) {
            if ($rules['platform'] === $currentPlatform && $rules['media_problems'] === []) {
                $current['format'] = $key;
                break;
            }
        }
    }

    $current['language'] ??= $currentChannel['language'] ?? array_key_first($languages);
    $currentExists = isset($packageMap[$current['channel'].'|'.$current['format'].'|'.$current['language']]);
    $canOpen = $can['packages'] && $film !== null && $social['policy'];
    $projectTitle = $social['project']['title'] !== '' ? $social['project']['title'] : $id;
    $finalUrl = route('video-projects.final-composition-preview', $id);
    $screenUrl = route('video-projects.social-publishing', $id);
@endphp
<div class="sp" data-social-screen data-sp-project="{{ $id }}">
    <header class="sp-head">
        <div class="sp-head-title">
            <span class="sp-head-icon"><i class="fas fa-film"></i></span>
            <div>
                <h1>Social Publishing (Phase 12)</h1>
                <p>Biến phim đã duyệt thành gói nội dung cho Facebook / YouTube. Tạo nội dung, cover, duyệt, xuất bản và đo lường hiệu quả.</p>
            </div>
        </div>
        <nav class="sp-steps" aria-label="Các bước của dự án">
            <a class="sp-step" href="{{ route('video-projects.keyframes', $id) }}">P9 Keyframes</a>
            <i class="fas fa-chevron-right sp-step-arrow"></i>
            <a class="sp-step" href="{{ route('video-projects.render-video', $id) }}">P10 Clips</a>
            <i class="fas fa-chevron-right sp-step-arrow"></i>
            <a class="sp-step" href="{{ $finalUrl }}">P11 Film</a>
            <i class="fas fa-chevron-right sp-step-arrow"></i>
            <span class="sp-step on" aria-current="page">P12 Social Publishing</span>
            <button type="button" class="sp-btn outline sp-help" data-sp-open="sp-modal-help"><i class="far fa-question-circle"></i> Hướng dẫn</button>
        </nav>
    </header>

    @foreach($social['problems'] as $problem)
        <div class="sp-alert" data-social-problem><i class="fas fa-exclamation-triangle"></i><div>{{ $problem }}</div></div>
    @endforeach

    {{-- Thanh chon: mo goi co san cua bo ba kenh / dinh dang / ngon ngu, hoac mo goi moi. --}}
    <form class="sp-toolbar" method="POST" action="{{ route('video-projects.social-package-store', $id) }}" data-sp-picker data-sp-packages='@json($packageMap)' data-sp-url="{{ $screenUrl }}" data-sp-current="{{ $sel['id'] ?? '' }}">
        @csrf
        <div class="sp-field sp-field-project">
            <span>Project</span>
            <a class="sp-project" href="{{ $finalUrl }}" title="Mở Final Composition của dự án">
                <i class="far fa-folder"></i><b>{{ $projectTitle }}</b>
                @if($film)
                    <span class="sp-badge ok"><i class="fas fa-check"></i> Film Approved</span>
                @else
                    <span class="sp-badge">Chưa có phim duyệt</span>
                @endif
            </a>
        </div>
        <div class="sp-field">
            <span>Package</span>
            <div class="sp-control" style="--sp-w:136px">
                <select data-sp-revision aria-label="Revision của gói" @disabled(! $sel)>
                    @if($sel)
                        @foreach(collect($sel['revisions'])->sortByDesc('revision') as $revision)
                            <option value="{{ $revision['id'] }}" @selected($revision['id'] === $sel['id'])>R{{ $revision['revision'] }} · {{ $revisionLabel[$revision['status']] ?? $revision['status'] }}</option>
                        @endforeach
                    @else
                        <option>Chưa mở gói</option>
                    @endif
                </select>
            </div>
        </div>
        <div class="sp-field">
            <span>Kênh</span>
            <div class="sp-control" style="--sp-w:250px">
                <i class="{{ $platformIcon($currentPlatform) }}" data-sp-channel-icon></i>
                <select name="channel_id" data-sp-pick="channel" aria-label="Kênh">
                    @if($sel && ! collect($activeChannels)->contains('id', $current['channel']))
                        <option value="{{ $current['channel'] }}" data-platform="{{ $currentPlatform }}" selected>{{ $sel['channel']['display_name'] }} (đã lưu trữ)</option>
                    @endif
                    @forelse($activeChannels as $channel)
                        <option value="{{ $channel['id'] }}" data-platform="{{ $channel['platform'] }}" @selected($channel['id'] === $current['channel'])>{{ $channel['display_name'] }}</option>
                    @empty
                        <option value="">Chưa có kênh</option>
                    @endforelse
                </select>
            </div>
        </div>
        <div class="sp-field">
            <span>Định dạng</span>
            <div class="sp-control" style="--sp-w:200px">
                <select name="format" data-sp-pick="format" aria-label="Định dạng">
                    @foreach($social['formats'] as $key => $rules)
                        <option value="{{ $key }}" data-platform="{{ $rules['platform'] }}" @selected($key === $current['format'])>{{ $rules['label'] }}{{ is_array($rules['media']['aspect'] ?? null) ? ' ('.$rules['media']['aspect']['width'].':'.$rules['media']['aspect']['height'].')' : '' }}{{ $rules['media_problems'] !== [] ? ' · phim chưa phù hợp' : '' }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="sp-field">
            <span>Ngôn ngữ</span>
            <div class="sp-control" style="--sp-w:150px">
                <i class="fas fa-language" style="color:#64748b"></i>
                <select name="language" data-sp-pick="language" aria-label="Ngôn ngữ">
                    @foreach($languages as $code => $name)
                        <option value="{{ $code }}" @selected($code === $current['language'])>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="sp-toolbar-end">
            <button type="button" class="sp-icon-btn" data-sp-menu-toggle aria-label="Thêm thao tác" aria-expanded="false"><i class="fas fa-ellipsis-h"></i></button>
            <div class="sp-menu" data-sp-menu hidden>
                <button type="button" data-sp-open="sp-modal-channels"><i class="fas fa-id-card"></i> Hồ sơ kênh và kết nối API</button>
                <button type="button" data-sp-open="sp-modal-packages"><i class="fas fa-layer-group"></i> Tất cả gói của dự án ({{ count($social['packages']) }})</button>
                <button type="button" data-sp-open="sp-modal-source"><i class="fas fa-ruler-combined"></i> Phim dùng được cho định dạng nào</button>
                <button type="button" data-sp-open="sp-modal-help"><i class="far fa-question-circle"></i> Hướng dẫn</button>
            </div>
        </div>
        @if($canOpen)
            <div class="sp-open-new" data-sp-open-new @if($sel) hidden @endif>
                <i class="fas fa-folder-plus" style="color:var(--sp-blue)"></i>
                <b data-sp-open-label>{{ $currentExists ? 'Gói cho lựa chọn này đã có.' : 'Chưa có gói cho lựa chọn này.' }}</b>
                <input type="text" name="goal" maxlength="500" placeholder="Mục tiêu bài đăng — để trống: dùng mục tiêu mặc định của kênh">
                <button class="sp-btn primary small" type="submit" data-social-package-form><i class="fas fa-folder-open"></i> Mở gói</button>
                <small>Mỗi gói thuộc đúng một kênh, một định dạng và một ngôn ngữ. Gói đã có thì mở lại gói đó. Mở gói không gọi AI.</small>
            </div>
        @endif
    </form>

    <div class="sp-layout">
        {{-- Cot trai: phim nguon va chuoi nguon ghim --}}
        <aside class="sp-col sp-col-source">
            @php
                $currentBadge = $film ? '<span class="sp-badge ok"><i class="fas fa-check"></i> Valid</span>' : '<span class="sp-badge">Chưa có</span>';
                $currentSource = [
                    'film' => $film,
                    'pins' => $social['source']['pins'] ?? null,
                    'assets' => $social['source']['assets'] ?? [],
                    'refs' => $social['source']['refs'] ?? 0,
                    'missing' => $social['source']['missing'] ?? [],
                    'badge' => $currentBadge,
                    'sourceButton' => true,
                ];
            @endphp
            @if(! $sel)
                @include('video-projects.partials.social-film', $currentSource + ['heading' => 'Nguồn phim (Approved Film)', 'scopeClass' => ''])
            @else
                {{-- Goi dang mo: phim va nguon ma chinh revision nay ghim, khong phai phim du an chon hom nay. --}}
                @include('video-projects.partials.social-film', [
                    'film' => $sel['film'],
                    'pins' => $sel['pins'],
                    'assets' => $sel['assets'],
                    'refs' => $sel['refs'],
                    'missing' => $sel['missing'],
                    'heading' => 'Nguồn phim của gói (R'.$sel['revision'].')',
                    'badge' => $sel['standing']['film_changed']
                        ? '<span class="sp-badge warn" data-sp-film-state="old" title="Dự án đã duyệt phim khác sau khi gói này được viết"><i class="fas fa-history"></i> Phim cũ</span>'
                        : '<span class="sp-badge ok" data-sp-film-state="current"><i class="fas fa-check"></i> Phim hiện hành</span>',
                    'scopeClass' => 'sp-when-attached',
                    'sourceButton' => false,
                ])
                @if($sel['standing']['film_changed'])
                    <section class="sp-card sp-when-attached" data-sp-film-compare>
                        <div class="sp-card-head"><h3>Phim đã duyệt hiện hành</h3><span class="sp-badge info">Để đối chiếu</span></div>
                        @if($film)
                            <ul class="sp-facts" style="margin-top:0">
                                <li><i class="fas fa-film"></i><span>Final</span><b>r{{ $film['revision'] }} · {{ substr($film['final_id'], 0, 8) }}…</b></li>
                                <li><i class="fas fa-fingerprint"></i><span>Hash</span><b>{{ substr($film['sha256'], 0, 14) }}…</b></li>
                                <li><i class="far fa-clock"></i><span>Thời lượng</span><b>{{ $mmss($film['media']['duration_ms']) }} · {{ $film['media']['width'] }} × {{ $film['media']['height'] }}</b></li>
                            </ul>
                            <p class="sp-sub" style="margin:8px 0 0">Gói R{{ $sel['revision'] }} được viết cho phim ở trên, không phải phim này. Bấm "Cập nhật theo nguồn hiện hành" ở tab Nội dung để tạo revision cho phim hiện hành.</p>
                        @else
                            <p class="sp-sub" style="margin:0">Dự án hiện không có phim đã duyệt dùng được.</p>
                        @endif
                    </section>
                @endif
                {{-- Khi thanh chon tro sang lua chon chua co goi, cot trai tro ve phim hien hanh cua du an. --}}
                @include('video-projects.partials.social-film', $currentSource + ['heading' => 'Phim đã duyệt hiện hành', 'scopeClass' => 'sp-when-detached'])
            @endif
        </aside>

        {{-- Cot giua: cac buoc lam viec voi goi --}}
        <main class="sp-col sp-col-work">
            <nav class="sp-tabs" role="tablist" aria-label="Các phần của gói">
                <button type="button" class="sp-tab on" role="tab" data-sp-tab="content"><i class="fas fa-pen-nib"></i> Nội dung <span class="sp-dot" data-sp-dirty-dot title="Có thay đổi chưa lưu" hidden></span></button>
                <button type="button" class="sp-tab" role="tab" data-sp-tab="cover"><i class="far fa-image"></i> Cover</button>
                <button type="button" class="sp-tab" role="tab" data-sp-tab="preview"><i class="far fa-eye"></i> Xem trước</button>
                <button type="button" class="sp-tab" role="tab" data-sp-tab="approval"><i class="fas fa-shield-alt"></i> Duyệt &amp; Đăng @if($sel && $sel['status'] === 'draft' && $sel['approval_problems'] !== []) <span class="sp-dot" title="Còn điều chặn duyệt"></span> @endif</button>
                <button type="button" class="sp-tab" role="tab" data-sp-tab="history"><i class="fas fa-chart-bar"></i> Lịch sử &amp; Hiệu quả</button>
            </nav>

            @if($sel)
                {{-- Thanh chon tro sang bo ba chua co goi: vung lam viec cua goi cu an di, de khong sua nham goi. --}}
                <section class="sp-card sp-when-detached" data-sp-detached-card>
                    <div class="sp-empty">
                        <i class="fas fa-random"></i>
                        <b>Lựa chọn trên thanh chưa có gói</b>
                        <span>Gói đang mở (R{{ $sel['revision'] }} · {{ $sel['channel']['display_name'] }} · {{ $sel['rules']['label'] }} · {{ $languages[$sel['language']] ?? $sel['language'] }}) đã được ẩn để không sửa nhầm gói. Bấm "Mở gói" trên thanh để mở gói cho lựa chọn mới, hoặc quay lại gói đang mở.</span>
                        <button type="button" class="sp-btn outline small" data-sp-reattach><i class="fas fa-undo"></i> Quay lại gói đang mở</button>
                    </div>
                </section>
            @endif

            @if(! $sel)
                @foreach(['content' => false, 'cover' => true, 'preview' => true, 'approval' => true] as $panel => $hidden)
                    <section class="sp-panel" data-sp-panel="{{ $panel }}" @if($hidden) hidden @endif>
                        <div class="sp-card">
                            <div class="sp-empty">
                                <i class="fas fa-folder-open"></i>
                                <b>Chưa mở gói nội dung nào</b>
                                <span>Chọn Kênh, Định dạng và Ngôn ngữ ở thanh trên: gói đã có thì trang mở gói đó, chưa có thì bấm "Mở gói".</span>
                                @if($social['packages'] !== [])
                                    <div class="sp-steps-row" style="justify-content:center">
                                        @foreach($social['packages'] as $row)
                                            <a class="sp-btn small" href="{{ route('video-projects.social-publishing', ['id' => $id, 'package' => $row['id']]) }}"><i class="{{ $platformIcon($row['platform']) }}"></i> {{ $row['channel'] }} · {{ $social['formats'][$row['format']]['label'] ?? $row['format'] }} · {{ $row['language'] }} · R{{ $row['revision'] }}</a>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        </div>
                    </section>
                @endforeach
            @else
                @php
                    $rules = $sel['rules'];
                    $fields = $sel['fields'];
                    $doc = $sel['document'];
                    $zone = $sel['channel']['timezone'] ?? 'UTC';
                    $editable = $can['packages'] && $sel['newest'];
                    $text = $rules['text'];
                    $nextRevision = 1 + (int) max(array_column($sel['revisions'], 'revision'));
                    $rendered = (string) $doc['rendered']['text'];
                    $titleLength = mb_strlen((string) $fields['title']);
                @endphp

                {{-- Tab: Noi dung --}}
                <section class="sp-panel sp-when-attached" data-sp-panel="content" data-social-content data-social-revision="{{ $sel['id'] }}">
                    @unless($sel['newest'])
                        <div class="sp-alert info" data-social-old-revision><i class="fas fa-history"></i><div>Đây là revision cũ (R{{ $sel['revision'] }}), chỉ để xem. Sửa và chạy AI trên bản mới nhất của gói.</div></div>
                    @endunless
                    @if($sel['standing']['film_changed'])
                        <div class="sp-alert bad" data-social-film-changed><i class="fas fa-exclamation-triangle"></i><div>Phim đã duyệt của dự án đã đổi: bản này tham chiếu bản phim trước (hash {{ substr($sel['film']['sha256'], 0, 12) }}…) và được giữ nguyên. Việc gửi và xuất tạm dừng cho đến khi bạn tạo revision theo phim hiện hành.</div></div>
                    @endif
                    @if($sel['standing']['channel_changed'])
                        <div class="sp-alert info" data-social-channel-changed><i class="fas fa-info-circle"></i><div>Hồ sơ kênh hiện tại đã khác lúc viết gói. Bản này giữ hồ sơ đã dùng; thay đổi chỉ áp dụng khi cập nhật theo nguồn hiện hành.</div></div>
                    @endif
                    @if($sel['standing']['policy_changed'])
                        <div class="sp-alert info" data-social-policy-changed><i class="fas fa-info-circle"></i><div>Chính sách nền tảng đã có revision mới so với lúc viết gói. Lịch sử của gói giữ nguyên; muốn duyệt hoặc đăng thì cập nhật theo nguồn hiện hành.</div></div>
                    @endif
                    @if($editable && ($sel['standing']['film_changed'] || $sel['standing']['channel_changed'] || $sel['standing']['policy_changed']))
                        <form method="POST" action="{{ route('video-projects.social-package-rebase', [$id, $sel['id']]) }}" data-social-rebase>
                            @csrf
                            <input type="hidden" name="expected_hash" value="{{ $sel['hash'] }}">
                            <button class="sp-btn primary" type="submit" data-sp-needs-clean><i class="fas fa-sync"></i> Cập nhật theo nguồn hiện hành (tạo revision mới)</button>
                        </form>
                    @endif

                    <div class="sp-duo">
                        <section class="sp-card" data-social-strategy>
                            <div class="sp-card-head"><h3><i class="fas fa-magic"></i> Chiến lược nội dung (AI đề xuất)</h3></div>
                            @if($sel['plan'])
                                <dl class="sp-strategy">
                                    <div><dt><i class="fas fa-user-friends"></i>Khán giả</dt><dd>{{ $sel['plan']['strategy']['audience'] }}</dd></div>
                                    <div><dt><i class="fas fa-bullseye"></i>Mục tiêu</dt><dd>{{ $sel['plan']['strategy']['goal'] }}</dd></div>
                                    <div><dt><i class="fas fa-compass"></i>Góc khai thác</dt><dd>{{ $sel['plan']['strategy']['angle'] }}</dd></div>
                                    <div><dt><i class="far fa-comment-dots"></i>Thông điệp</dt><dd>{{ $sel['plan']['strategy']['message'] }}</dd></div>
                                    <div><dt><i class="far fa-lightbulb"></i>Lý do</dt><dd>{{ $sel['plan']['strategy']['rationale'] }}</dd></div>
                                </dl>
                            @else
                                <div class="sp-empty">
                                    <i class="fas fa-magic"></i>
                                    <span>Chưa có đề xuất của AI cho gói này. {{ $flags['ai_content'] ? 'Bấm "Tạo nội dung (AI)" để AI đề xuất chiến lược, hook và nội dung.' : 'Tạo nội dung bằng AI đang tắt — viết và sửa gói bằng tay.' }}</span>
                                    @if($sel['goal'])<small>Mục tiêu của gói: {{ $sel['goal'] }}</small>@endif
                                </div>
                            @endif
                        </section>
                        <section class="sp-card">
                            <div class="sp-card-head"><h3><i class="far fa-dot-circle" style="color:#7c3aed"></i> Hook (mở đầu)</h3></div>
                            @if($sel['plan'] && $sel['plan']['hook_options'] !== [])
                                {{-- Moi hook la mot lua chon cua form noi dung (thuoc tinh form): chon hook dat cau mo dau trong form, luu cung cac truong khac. --}}
                                <div class="sp-hooks" data-social-hooks role="radiogroup" aria-label="Hook mở đầu">
                                    @foreach($sel['plan']['hook_options'] as $hook)
                                        <label class="sp-hook {{ $fields['hook_id'] === $hook['id'] ? 'on' : '' }}">
                                            <input type="radio" name="hook_id" value="{{ $hook['id'] }}" form="sp-content-form" data-sp-hook-text="{{ $hook['text'] }}" @checked($fields['hook_id'] === $hook['id']) @disabled(! $editable)>
                                            <span class="sp-radio"></span><span>{{ $hook['text'] }}<small>{{ $hook['rationale'] }}</small></span>
                                        </label>
                                    @endforeach
                                </div>
                                <p class="sp-sub" style="margin:10px 0 0">Chọn hook sẽ đặt câu mở đầu trong phần Nội dung đăng bên dưới; bấm "Lưu &amp; Tạo phiên bản" để lưu cùng các thay đổi khác.</p>
                            @else
                                <div class="sp-empty"><i class="far fa-comment-dots"></i><span>Hook do AI đề xuất hiện ở đây. Câu mở đầu vẫn sửa được trong phần Nội dung đăng.</span></div>
                            @endif
                        </section>
                    </div>

                    @if($sel['plan'] && ($sel['plan']['claims'] !== [] || $sel['plan']['review_notes'] !== []))
                        <details class="sp-fold sp-card">
                            <summary>Khẳng định cụ thể và điểm cần bạn xác nhận ({{ count($sel['plan']['claims']) + count($sel['plan']['review_notes']) }})</summary>
                            @if($sel['plan']['claims'] !== [])
                                <b>Khẳng định và nguồn</b>
                                <ul class="social-list" data-social-claims>@foreach($sel['plan']['claims'] as $claim)<li>{{ $claim['text'] }} <small>← {{ implode(', ', $claim['source_refs']) }}</small></li>@endforeach</ul>
                            @endif
                            @if($sel['plan']['review_notes'] !== [])
                                <b>Điểm cần bạn xác nhận</b>
                                <ul class="social-list" data-social-notes>@foreach($sel['plan']['review_notes'] as $note)<li><b>{{ $note['topic'] }}:</b> {{ $note['note'] }}</li>@endforeach</ul>
                            @endif
                        </details>
                    @endif

                    <section class="sp-card">
                        <div class="sp-card-head">
                            <h3><i class="fas fa-pen-fancy"></i> Nội dung đăng (Content)</h3>
                            @if($editable)
                                <div class="sp-actions" data-social-ai>
                                    @if($flags['ai_content'])
                                        <form method="POST" action="{{ route('video-projects.social-package-write', [$id, $sel['id']]) }}">
                                            @csrf
                                            <input type="hidden" name="expected_hash" value="{{ $sel['hash'] }}"><input type="hidden" name="operation_id" value="{{ $uuid() }}"><input type="hidden" name="regenerate" value="0">
                                            <button class="sp-btn ai" type="submit" data-social-write data-sp-needs-clean title="Tạo nội dung — tính phí"><i class="fas fa-magic"></i> Tạo nội dung (AI) <span class="sp-paid">tính phí</span></button>
                                        </form>
                                        @if($sel['plan'])
                                            <form method="POST" action="{{ route('video-projects.social-package-write', [$id, $sel['id']]) }}">
                                                @csrf
                                                <input type="hidden" name="expected_hash" value="{{ $sel['hash'] }}"><input type="hidden" name="operation_id" value="{{ $uuid() }}"><input type="hidden" name="regenerate" value="1">
                                                <button class="sp-btn outline" type="submit" data-sp-needs-clean title="Viết lại — tính phí"><i class="fas fa-sync-alt"></i> Viết lại <span class="sp-paid">tính phí</span></button>
                                            </form>
                                        @endif
                                    @else
                                        <span class="sp-sub" data-social-ai-off>Tạo nội dung bằng AI đang tắt — viết và sửa gói bằng tay.</span>
                                    @endif
                                </div>
                            @endif
                        </div>
                        <div class="sp-alert info" data-social-basis><i class="fas fa-info-circle"></i><div>AI ở màn này chỉ được gửi kịch bản, timeline shot, card chữ và thiết kế của chính bản phim — không gửi hình ảnh hay âm thanh. Nội dung và review của AI là đánh giá từ các nguồn đó, không phải AI đã xem phim. Mỗi lần bấm nút AI là một lượt gọi model có tính phí; AI viết ra bản nháp mới để bạn sửa, AI không duyệt và không đăng.</div></div>

                        <form method="POST" action="{{ route('video-projects.social-package-save', [$id, $sel['id']]) }}" class="sp-form" id="sp-content-form" data-social-edit>
                            @csrf
                            <input type="hidden" name="expected_hash" value="{{ $sel['hash'] }}">
                            <fieldset @disabled(! $editable)>
                                @if($text['title']['used'])
                                    <div class="sp-input">
                                        <label for="sp-title">Tiêu đề (Title) @if($text['title']['required'])<span class="sp-req">*</span> @endif <small>{{ $limit($text['title']) }}</small></label>
                                        <div class="sp-box"><input id="sp-title" type="text" name="title" value="{{ $fields['title'] }}" data-sp-count data-sp-max="{{ $text['title']['max_chars'] ?? '' }}"><span class="sp-count"></span></div>
                                    </div>
                                @else
                                    <p class="sp-sub" style="margin:0">{{ $rules['label'] }} không dùng tiêu đề.</p>
                                @endif
                                <div class="sp-input">
                                    <label for="sp-opening">Câu mở đầu (Hook)</label>
                                    <div class="sp-box"><textarea id="sp-opening" name="opening" rows="2" style="min-height:62px" data-sp-count>{{ $fields['opening'] }}</textarea><span class="sp-count"></span></div>
                                </div>
                                <div class="sp-input">
                                    <label for="sp-body">Caption / Description <small>phần thân bài</small></label>
                                    <div class="sp-box"><textarea id="sp-body" name="body" rows="5" data-sp-count>{{ $fields['body'] }}</textarea><span class="sp-count"></span></div>
                                    <div class="sp-total">Toàn bộ nội dung sẽ đăng của bản đang mở (câu mở đầu, thân bài, CTA, liên kết, câu công bố, hashtag): <b>{{ mb_strlen($rendered) }} ký tự · {{ strlen($rendered) }} byte</b> — {{ $limit($text['body']) }}.</div>
                                </div>
                                <div class="sp-row2">
                                    <div class="sp-input">
                                        <label for="sp-cta">Call to action (CTA)</label>
                                        <div class="sp-box"><input id="sp-cta" type="text" name="cta" value="{{ $fields['cta'] }}" data-sp-count><span class="sp-count"></span></div>
                                    </div>
                                    <div class="sp-input">
                                        <label for="sp-link">Liên kết <small>chèn ngay dưới CTA</small></label>
                                        <div class="sp-box"><input id="sp-link" type="url" name="link" value="{{ $fields['link'] }}" style="padding-right:12px"></div>
                                    </div>
                                </div>
                                <div class="sp-input">
                                    <label for="sp-hashtags">Hashtags <small>cách nhau bằng dấu cách</small></label>
                                    <div class="sp-box"><input id="sp-hashtags" type="text" name="hashtags" value="{{ implode(' ', array_map(static fn (string $hashtag): string => '#'.$hashtag, $fields['hashtags'])) }}" data-sp-count="hashtags" data-sp-max="{{ $text['hashtags']['max_count'] ?? '' }}"><span class="sp-count"></span></div>
                                </div>
                                @if($text['keywords']['used'])
                                    <div class="sp-input">
                                        <label for="sp-keywords">Từ khóa / tags <small>cách nhau bằng dấu phẩy</small></label>
                                        <div class="sp-box"><input id="sp-keywords" type="text" name="keywords" value="{{ implode(', ', $fields['keywords']) }}" data-sp-count="tags" data-sp-max="{{ $text['keywords']['max_total_chars'] ?? '' }}"><span class="sp-count"></span></div>
                                    </div>
                                @endif
                                <div class="sp-disclosure">
                                    <label class="sp-checkline"><input type="checkbox" data-sp-disclosure-toggle @checked($fields['disclosure_text'] !== null)> Thêm câu công bố AI vào nội dung cuối</label>
                                    <input type="hidden" name="disclosure_text" value="">
                                    <input type="text" name="disclosure_text" value="{{ $fields['disclosure_text'] ?? ($sel['channel']['disclosure']['texts'][$sel['language']] ?? '') }}" data-sp-disclosure-text aria-label="Câu công bố trong nội dung" @disabled($fields['disclosure_text'] === null)>
                                    <label class="sp-checkline"><input type="hidden" name="synthetic_media" value="0"><input type="checkbox" name="synthetic_media" value="1" @checked($fields['synthetic_media'])> Khai báo "nội dung tổng hợp/AI" của nền tảng <small>— {{ $rules['synthetic_media']['api'] ? 'gửi qua API khi đăng' : 'adapter không gửi qua API: bật bằng tay trên nền tảng' }}</small></label>
                                </div>
                                @if($rules['metadata']['privacy'] !== [] || $rules['metadata']['made_for_kids'])
                                    <div class="sp-row2">
                                        @if($rules['metadata']['privacy'] !== [])
                                            <div class="sp-input">
                                                <label for="sp-privacy">Chế độ hiển thị</label>
                                                <div class="sp-box"><select id="sp-privacy" name="privacy">@foreach($rules['metadata']['privacy'] as $privacy)<option value="{{ $privacy }}" @selected($fields['privacy'] === $privacy)>{{ $privacy }}</option>@endforeach</select></div>
                                            </div>
                                        @endif
                                        @if($rules['metadata']['made_for_kids'])
                                            <label class="sp-checkline" style="align-self:end;padding-bottom:10px"><input type="hidden" name="made_for_kids" value="0"><input type="checkbox" name="made_for_kids" value="1" @checked($fields['made_for_kids'])> Nội dung dành cho trẻ em <small>(khai báo của nền tảng)</small></label>
                                        @endif
                                    </div>
                                @endif
                                @if($editable)
                                    <div class="sp-form-foot">
                                        <button type="reset" class="sp-btn" title="Trả các ô về bản đang mở"><i class="fas fa-undo"></i> Hoàn tác</button>
                                        <div>
                                            <span class="sp-dirty" data-sp-dirty-note hidden><i class="fas fa-circle"></i> Có thay đổi chưa lưu</span>
                                            <button class="sp-btn primary" type="submit" data-social-save><i class="fas fa-layer-group"></i> Lưu &amp; Tạo phiên bản (R{{ $nextRevision }})</button>
                                        </div>
                                    </div>
                                    <p class="sp-sub" style="margin:0">Lưu tạo một revision nháp mới và không gọi AI; revision đã duyệt giữ nguyên.</p>
                                @endif
                            </fieldset>
                        </form>
                    </section>

                    @if($sel['runs'] !== [])
                        <details class="sp-fold sp-card" data-social-runs>
                            <summary>Các lượt AI viết nội dung của gói ({{ count($sel['runs']) }})</summary>
                            <div class="sp-table-wrap">
                                <table class="sp-table">
                                    <thead><tr><th>Lúc</th><th>Trạng thái</th><th>Kết quả</th><th>Chi phí</th></tr></thead>
                                    <tbody>
                                    @foreach($sel['runs'] as $run)
                                        <tr data-social-run="{{ $run['id'] }}">
                                            <td>{{ $run['at'] }}</td>
                                            <td>{{ $run['status'] }} {{ $run['verdict'] ? '· '.$run['verdict'] : '' }}</td>
                                            <td>
                                                @if($run['applied_revision'])thành revision {{ $run['applied_revision'] }}
                                                @elseif($run['not_applied'])<span class="social-tag warn" data-social-run-left>không áp dụng</span> {{ $run['not_applied'] }} — phản hồi và chi phí được giữ, nội dung hiện tại không bị ghi đè
                                                @elseif($run['error']){{ $run['error'] }}
                                                @elseif($run['faults'] !== []){{ implode(' | ', array_slice($run['faults'], 0, 4)) }}
                                                @else — @endif
                                                @foreach($run['warnings'] as $warning)<br><small>{{ $warning }}</small>@endforeach
                                            </td>
                                            <td>{{ $run['cost_usd'] === null ? '—' : '$'.number_format($run['cost_usd'], 4) }} <small>({{ $run['tokens']['in'] }}/{{ $run['tokens']['out'] }} token)</small></td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </details>
                    @endif
                </section>

                {{-- Tab: Cover --}}
                <section class="sp-panel sp-when-attached" data-sp-panel="cover" data-social-artwork hidden>
                    <section class="sp-card">
                        <div class="sp-card-head">
                            <h3><i class="far fa-image"></i> Thumbnail / Cover</h3>
                            <span class="sp-badge">{{ $rules['cover']['width'] }}×{{ $rules['cover']['height'] }} JPEG</span>
                        </div>
                        <p class="sp-sub" style="margin:0 0 12px">Code trích frame, crop và viết chữ bằng FFmpeg; không dùng AI vẽ lại hay sửa hình. {{ $rules['cover']['api'] ? 'Định dạng này đặt cover qua API sau khi nền tảng xử lý xong video.' : 'Adapter của định dạng này không đặt cover qua API: file cover nằm trong gói tải về để đặt bằng tay.' }}</p>
                        @if($sel['cover_problem'])
                            <div class="sp-alert bad" data-social-cover-problem><i class="fas fa-ban"></i><div>Cover của bản này: {{ $sel['cover_problem'] }}.</div></div>
                        @endif
                        @if($sel['covers'] !== [])
                            <div class="sp-cover-grid" data-social-covers>
                                @foreach($sel['covers'] as $cover)
                                    @php $chosen = ($sel['artwork']['artifact_id'] ?? null) === $cover['id']; @endphp
                                    <figure class="sp-cover {{ $chosen ? 'on' : '' }}" data-social-cover="{{ $cover['id'] }}">
                                        <img src="{{ route('video-projects.social-cover', [$id, $cover['id']]) }}" alt="Cover" loading="lazy">
                                        <figcaption>
                                            <span>{{ $cover['recipe']['source']['kind'] === 'film_frame' ? 'frame '.$clock($cover['recipe']['source']['at_ms']) : $cover['recipe']['source']['asset_id'] }}{{ $cover['recipe']['text'] ? ' · "'.$cover['recipe']['text']['value'].'"' : '' }}</span>
                                            @if($editable && ! $chosen)
                                                <form method="POST" action="{{ route('video-projects.social-cover-select', [$id, $sel['id']]) }}">@csrf<input type="hidden" name="expected_hash" value="{{ $sel['hash'] }}"><input type="hidden" name="artifact_id" value="{{ $cover['id'] }}"><button class="sp-btn small block" type="submit" data-sp-needs-clean>Chọn vào gói</button></form>
                                            @elseif($chosen)
                                                <span class="sp-badge info">Đang dùng</span>
                                            @endif
                                        </figcaption>
                                    </figure>
                                @endforeach
                            </div>
                        @else
                            <div class="sp-empty"><i class="far fa-image"></i><span>Chưa có cover nào cho gói này.</span></div>
                        @endif
                        @if($sel['artwork'] && $editable)
                            <form method="POST" action="{{ route('video-projects.social-cover-select', [$id, $sel['id']]) }}" style="margin-top:12px">@csrf<input type="hidden" name="expected_hash" value="{{ $sel['hash'] }}"><button class="sp-btn danger small" type="submit" data-sp-needs-clean><i class="fas fa-times"></i> Bỏ cover khỏi gói</button></form>
                        @endif
                    </section>

                    @if($editable && ! $sel['standing']['film_changed'])
                        @php $duration = max(1, (int) $sel['film']['media']['duration_ms']); @endphp
                        <section class="sp-card">
                            <div class="sp-card-head"><h3><i class="fas fa-crop-alt"></i> Tạo cover từ phim</h3></div>
                            <form method="POST" action="{{ route('video-projects.social-cover-make', [$id, $sel['id']]) }}" class="sp-form" data-social-cover-form>
                                @csrf
                                <input type="hidden" name="expected_hash" value="{{ $sel['hash'] }}">
                                <div class="sp-frame-pick">
                                    <div class="sp-form">
                                        <div class="sp-input">
                                            <label>Thời điểm trong phim <small>0–{{ $duration - 1 }} ms</small></label>
                                            <input type="range" min="0" max="{{ $duration - 1 }}" step="1" value="{{ intdiv($duration, 2) }}" data-sp-at-range aria-label="Thời điểm trong phim">
                                        </div>
                                        <div class="sp-row2">
                                            <div class="sp-input"><label>Mili-giây</label><input type="number" name="at_ms" min="0" max="{{ $duration - 1 }}" value="{{ intdiv($duration, 2) }}" data-social-at></div>
                                            <div class="sp-input"><label>…hoặc ảnh nguồn</label><select name="asset_id"><option value="">— dùng thời điểm bên trái —</option>@foreach($sel['assets'] as $asset)<option value="{{ $asset['id'] }}">{{ $asset['id'] }}{{ isset($asset['at_ms']) ? ' ('.$clock($asset['at_ms']).')' : '' }}</option>@endforeach</select></div>
                                        </div>
                                        <div><button class="sp-btn outline small" type="button" data-social-frame-button data-social-frame-url="{{ route('video-projects.social-frame', [$id, $sel['id']]) }}"><i class="far fa-eye"></i> Trích frame để xem</button></div>
                                    </div>
                                    <img class="sp-frame" data-social-frame alt="Frame xem trước" hidden>
                                </div>
                                <div class="sp-row2">
                                    <div class="sp-input"><label>Tiêu điểm ngang (%)</label><input type="number" name="focus_x" min="0" max="100" value="50"></div>
                                    <div class="sp-input"><label>Tiêu điểm dọc (%)</label><input type="number" name="focus_y" min="0" max="100" value="50"></div>
                                </div>
                                <div class="sp-row2">
                                    <div class="sp-input"><label>Phóng (%)</label><input type="number" name="zoom" min="100" max="400" value="100"></div>
                                    <div class="sp-input"><label>Chữ trên cover <small>để trống: không có chữ</small></label><input type="text" name="text" maxlength="120"></div>
                                </div>
                                <div class="sp-row2">
                                    <div class="sp-input"><label>Vị trí chữ</label><select name="position"><option value="bottom">Dưới</option><option value="middle">Giữa</option><option value="top">Trên</option></select></div>
                                    <div class="sp-input"><label>Cỡ chữ</label><select name="size"><option value="medium">Vừa</option><option value="small">Nhỏ</option><option value="large">Lớn</option></select></div>
                                </div>
                                <div><button class="sp-btn primary" type="submit" data-social-cover-make data-sp-needs-clean><i class="far fa-image"></i> Tạo cover (không gọi AI)</button></div>
                            </form>
                        </section>
                    @endif

                    @if($sel['plan'] && $sel['plan']['artwork_options'] !== [])
                        <section class="sp-card">
                            <div class="sp-card-head"><h3><i class="fas fa-magic"></i> AI đề xuất cover</h3></div>
                            <ul class="social-list" data-social-artwork-options>
                                @foreach($sel['plan']['artwork_options'] as $option)
                                    <li><b>{{ $option['asset_id'] }}</b> — {{ $option['layout'] }}{{ $option['overlay_text'] ? ' · chữ: "'.$option['overlay_text'].'"' : '' }} <small>({{ $option['rationale'] }})</small></li>
                                @endforeach
                            </ul>
                        </section>
                    @endif
                </section>

                {{-- Tab: Xem truoc (nguyen van) --}}
                <section class="sp-panel sp-when-attached" data-sp-panel="preview" data-social-preview hidden>
                    <div class="sp-alert" data-sp-dirty-warning hidden><i class="fas fa-exclamation-circle"></i><div>Bạn có thay đổi chưa lưu ở tab Nội dung. Bên dưới là <b>bản đã lưu R{{ $sel['revision'] }}</b>, chưa gồm phần đang sửa; cột "Xem trước bài đăng" bên phải đang hiện bản đang chỉnh. Lưu để tạo revision mới, hoặc bấm Hoàn tác.</div></div>
                    <section class="sp-card">
                        <div class="sp-card-head"><h3><i class="far fa-file-alt"></i> Nội dung sẽ đăng — nguyên văn</h3><span class="sp-badge">Bản đã lưu R{{ $sel['revision'] }}</span></div>
                        <p class="sp-sub" style="margin:0 0 10px">Mọi phần dưới đây là phần sẽ được đăng và đều nằm trong hash duyệt. Bản mô phỏng bài đăng ở cột phải chỉ để đọc lại bố cục, không giống tuyệt đối giao diện của nền tảng.</p>
                        <span class="sp-label">Tiêu đề</span>
                        <pre class="sp-verbatim">{{ $doc['rendered']['title'] ?? '(không có)' }}</pre>
                        <span class="sp-label" style="margin-top:10px">Nội dung đăng</span>
                        <pre class="sp-verbatim" data-social-rendered-text>{{ $rendered === '' ? '(trống)' : $rendered }}</pre>
                        <span class="sp-label" style="margin-top:10px">Từ khóa / tags</span>
                        <pre class="sp-verbatim">{{ $doc['rendered']['tags'] === [] ? '(không có)' : implode(', ', $doc['rendered']['tags']) }}</pre>
                    </section>
                    <section class="sp-card">
                        <dl class="sp-kv">
                            <dt>Câu công bố trong nội dung</dt><dd data-social-disclosure-text>{{ $doc['content']['disclosure_text'] ?? 'không chèn' }}</dd>
                            <dt>Khai báo AI của nền tảng</dt><dd data-social-disclosure-flag>{{ $doc['disclosure']['synthetic_media'] ? 'CÓ' : 'không' }} — {{ $rules['synthetic_media']['api'] ? 'gửi qua API' : 'đặt bằng tay trên nền tảng' }}</dd>
                            <dt>Chế độ hiển thị</dt><dd>{{ $doc['metadata']['privacy'] ?? 'nền tảng không có lựa chọn này' }}</dd>
                            <dt>Hash gói</dt><dd data-social-package-hash>{{ $sel['hash'] }}</dd>
                            <dt>Nguồn nội dung</dt><dd>{{ $sel['origin'] === 'model' ? 'AI viết, người sửa và duyệt' : 'người viết' }}</dd>
                        </dl>
                    </section>
                </section>

                {{-- Tab: Duyet va dang --}}
                <section class="sp-panel sp-when-attached" data-sp-panel="approval" data-social-approval hidden>
                    <div class="sp-alert bad" data-sp-dirty-warning hidden><i class="fas fa-exclamation-circle"></i><div>Bạn có thay đổi chưa lưu ở tab Nội dung. Duyệt, review, tải gói và đăng đều áp dụng cho <b>bản đã lưu R{{ $sel['revision'] }}</b>, không gồm phần đang sửa — các nút này tạm khóa cho tới khi bạn lưu hoặc hoàn tác.</div></div>
                    <section class="sp-card">
                        <div class="sp-card-head"><h3><i class="fas fa-shield-alt"></i> Duyệt gói</h3>{!! $tag($sel['status']) !!}</div>
                        @if($sel['status'] === 'draft')
                            @foreach($sel['approval_problems'] as $problem)
                                <div class="sp-alert bad" data-social-approval-problem><i class="fas fa-ban"></i><div>{{ $problem }}</div></div>
                            @endforeach
                            @if($can['approve'] && $sel['approval_problems'] === [])
                                <form method="POST" action="{{ route('video-projects.social-package-approve', [$id, $sel['id']]) }}" class="sp-steps-row">
                                    @csrf
                                    <input type="hidden" name="expected_hash" value="{{ $sel['hash'] }}"><input type="hidden" name="operation_id" value="{{ $uuid() }}">
                                    <button class="sp-btn success" type="submit" data-social-approve data-sp-needs-clean><i class="fas fa-check"></i> Duyệt gói (R{{ $sel['revision'] }})</button>
                                    <span class="sp-sub">Duyệt đúng nội dung của bản xem trước. Duyệt không đăng gì lên nền tảng.</span>
                                </form>
                            @elseif(! $can['approve'])
                                <p class="sp-sub" style="margin:0">Tài khoản của bạn không có quyền duyệt gói.</p>
                            @endif
                        @elseif($sel['approved'])
                            <div class="sp-alert ok" data-social-approved><i class="fas fa-check-circle"></i><div>Đã duyệt {{ $sel['decision']['at'] ?? '' }}{{ ($sel['decision']['by'] ?? null) ? ' bởi '.$sel['decision']['by'] : '' }}. Muốn sửa nội dung: lưu chỉnh sửa trên bản mới nhất — tạo revision nháp mới, bản này và lịch sử của nó giữ nguyên.</div></div>
                            <div class="sp-steps-row">
                                @if($can['export'])
                                    <a class="sp-btn primary" href="{{ route('video-projects.social-package-export', [$id, $sel['id']]) }}" data-social-export data-sp-needs-clean><i class="fas fa-file-archive"></i> Tải gói (video, cover, nội dung, metadata, manifest)</a>
                                @endif
                                @if($can['approve'])
                                    <form method="POST" action="{{ route('video-projects.social-package-revoke', [$id, $sel['id']]) }}" class="sp-steps-row sp-form" style="gap:8px">
                                        @csrf
                                        <input type="hidden" name="operation_id" value="{{ $uuid() }}">
                                        <input type="text" name="reason" placeholder="Lý do thu hồi" maxlength="500" style="width:220px;height:38px">
                                        <button class="sp-btn danger" type="submit" data-social-revoke><i class="fas fa-undo"></i> Thu hồi duyệt</button>
                                    </form>
                                @endif
                            </div>
                            <p class="sp-sub" style="margin:8px 0 0">Tải gói không gọi AI và không đổi trạng thái gói. Tải gói không có nghĩa là đã đăng.</p>
                        @else
                            <div class="sp-alert" data-social-revoked><i class="fas fa-undo"></i><div>Bản này đã bị thu hồi duyệt. Thu hồi trong hệ thống không có nghĩa bài trên nền tảng đã biến mất — xem từng lượt đăng bên dưới.</div></div>
                        @endif
                    </section>

                    <section class="sp-card" data-social-review>
                        <div class="sp-card-head">
                            <h3><i class="fas fa-search"></i> Review của AI cho revision này</h3>
                            @if($editable && $flags['ai_content'])
                                <form method="POST" action="{{ route('video-projects.social-package-review', [$id, $sel['id']]) }}">
                                    @csrf
                                    <input type="hidden" name="expected_hash" value="{{ $sel['hash'] }}"><input type="hidden" name="operation_id" value="{{ $uuid() }}">
                                    <input type="hidden" name="regenerate" value="{{ $sel['review'] ? 1 : 0 }}">
                                    <button class="sp-btn ai small" type="submit" data-social-review-run data-sp-needs-clean><i class="fas fa-search"></i> {{ $sel['review'] ? 'Review lại' : 'AI review bản này' }} <span class="sp-paid">tính phí</span></button>
                                </form>
                            @endif
                        </div>
                        @if($sel['review'] === null)
                            <span class="sp-badge warn" data-social-not-reviewed>Chưa review</span> <span class="sp-sub">Chưa review không có nghĩa là đạt.</span>
                        @elseif($sel['review']['pending'] || $sel['review']['running'])
                            <span class="sp-badge warn">Đang chạy…</span>
                        @elseif($sel['review']['status'] !== 'succeeded' || $sel['review']['verdict'] !== 'PASS')
                            <span class="sp-badge bad">Lượt review không dùng được</span> <span class="sp-sub">{{ $sel['review']['error'] ?? implode(' | ', array_slice($sel['review']['faults'], 0, 3)) }}</span>
                        @else
                            <span class="sp-badge info">{{ count($sel['review']['findings']) }} phát hiện</span> <span class="sp-sub">{{ $sel['review']['at'] }} · {{ $sel['review']['model'] }}{{ $sel['review']['cost_usd'] !== null ? ' · $'.number_format($sel['review']['cost_usd'], 4) : '' }}. Review không sửa và không duyệt gói.</span>
                            <p style="margin:10px 0 6px">{{ $sel['review']['summary'] }}</p>
                            <ul class="social-list">
                                @foreach($sel['review']['findings'] as $finding)
                                    <li data-social-finding="{{ $finding['id'] }}"><span class="social-tag {{ $finding['severity'] === 'major' ? 'bad' : ($finding['severity'] === 'minor' ? 'warn' : '') }}">{{ $finding['severity'] }}</span> <b>{{ $finding['location'] }}</b>: {{ $finding['issue'] }} @if($finding['source_refs'] !== []) <small>← {{ implode(', ', $finding['source_refs']) }}</small> @endif @if($finding['suggestion'])<br><small>Đề nghị: {{ $finding['suggestion'] }}</small>@endif</li>
                                @endforeach
                            </ul>
                            @foreach($sel['review']['not_reviewed'] as $gap)<small class="sp-sub">Không review được: {{ $gap }}</small><br>@endforeach
                        @endif
                    </section>

                    @if($sel['approved'])
                        @if($can['export'])
                            <section class="sp-card">
                                <div class="sp-card-head"><h3><i class="fas fa-flag-checkered"></i> Ghi nhận đã đăng thủ công</h3></div>
                                <form method="POST" action="{{ route('video-projects.social-manual', [$id, $sel['id']]) }}" class="sp-form" data-social-manual>
                                    @csrf
                                    <input type="hidden" name="expected_hash" value="{{ $sel['hash'] }}"><input type="hidden" name="request_key" value="{{ $uuid() }}">
                                    <div class="sp-row2">
                                        <div class="sp-input"><label>URL bài đăng</label><input type="url" name="url" required maxlength="500"></div>
                                        <div class="sp-input"><label>Thời điểm đăng <small>giờ {{ $zone }}</small></label><input type="datetime-local" name="posted_at" required></div>
                                    </div>
                                    <div class="sp-steps-row"><button class="sp-btn" type="submit" data-sp-needs-clean><i class="fas fa-flag-checkered"></i> Ghi nhận đã đăng thủ công</button><span class="sp-sub">Đây là xác nhận của bạn. Hệ thống không đối chiếu nội dung trên nền tảng với gói đã xuất.</span></div>
                                </form>
                            </section>
                        @endif

                        <section class="sp-card">
                            <div class="sp-card-head"><h3><i class="fas fa-paper-plane"></i> Đăng qua API</h3><i class="{{ $platformIcon($rules['platform']) }}" style="font-size:20px"></i></div>
                            @if(! $sel['api']['enabled'])
                                <div class="sp-alert info" data-social-api-off><i class="fas fa-info-circle"></i><div>Đăng {{ $platforms[$rules['platform']] ?? $rules['platform'] }} qua API đang tắt. Dùng "Tải gói" rồi ghi nhận đăng thủ công.</div></div>
                            @elseif($sel['api']['problems'] !== [])
                                @foreach($sel['api']['problems'] as $problem)<div class="sp-alert bad" data-social-api-problem><i class="fas fa-ban"></i><div>{{ $problem }}</div></div>@endforeach
                            @elseif($sel['standing']['film_changed'])
                                <div class="sp-alert"><i class="fas fa-pause-circle"></i><div>Tạm dừng gửi: gói tham chiếu bản phim trước.</div></div>
                            @elseif(! $can['publish'])
                                <p class="sp-sub" style="margin:0">Tài khoản của bạn không có quyền đăng qua API.</p>
                            @else
                                <form method="POST" action="{{ route('video-projects.social-publish', [$id, $sel['id']]) }}" class="sp-form" data-social-publish>
                                    @csrf
                                    <input type="hidden" name="expected_hash" value="{{ $sel['hash'] }}"><input type="hidden" name="request_key" value="{{ $uuid() }}">
                                    @if($rules['schedule']['supported'])
                                        <div class="sp-input"><label>Lên lịch <small>giờ {{ $zone }}; để trống là đăng ngay. {{ $platforms[$rules['platform']] ?? $rules['platform'] }} giữ lịch — hệ thống không giữ lịch song song.</small></label><input type="datetime-local" name="scheduled_at" style="max-width:280px"></div>
                                    @endif
                                    @if(collect($sel['publications'])->whereNotIn('status', ['cancelled', 'failed'])->isNotEmpty())
                                        <label class="sp-checkline"><input type="checkbox" name="again" value="1"> Đăng thêm một bài mới <small>(bản này đã có lượt đăng) — chủ ý, không phải gửi lại do lỗi mạng</small></label>
                                    @endif
                                    <div class="sp-steps-row"><button class="sp-btn primary" type="submit" data-sp-needs-clean><i class="fas fa-paper-plane"></i> Đăng ngay / Lên lịch R{{ $sel['revision'] }}</button><span class="sp-sub">Gửi đúng revision đang mở. Bấm hai lần không tạo hai lượt gửi.</span></div>
                                </form>
                            @endif
                        </section>
                    @endif

                    @if($sel['publications'] !== [])
                        <section class="sp-card">
                            <div class="sp-card-head"><h3><i class="fas fa-stream"></i> Lượt đăng của revision này</h3></div>
                            @foreach($sel['publications'] as $posting)
                                @include('video-projects.partials.social-posting', ['posting' => $posting, 'zone' => $zone, 'detail' => true])
                            @endforeach
                        </section>
                    @endif

                    <details class="sp-fold sp-card" data-social-revisions>
                        <summary>Lịch sử revision của gói ({{ count($sel['revisions']) }})</summary>
                        <div class="sp-table-wrap">
                            <table class="sp-table">
                                <thead><tr><th>Revision</th><th>Trạng thái</th><th>Lúc tạo</th><th>Hash</th><th></th></tr></thead>
                                <tbody>
                                @foreach(collect($sel['revisions'])->sortByDesc('revision') as $revision)
                                    <tr data-social-revision-row="{{ $revision['revision'] }}" class="{{ $revision['id'] === $sel['id'] ? 'on' : '' }}">
                                        <td>R{{ $revision['revision'] }}</td><td>{!! $tag($revision['status']) !!}</td><td>{{ $revision['at'] }}</td><td>{{ substr($revision['hash'], 7, 12) }}…</td>
                                        <td>@if($revision['id'] !== $sel['id'])<a class="sp-btn small" href="{{ route('video-projects.social-publishing', ['id' => $id, 'package' => $revision['id']]) }}">Xem</a>@else <span class="sp-sub">đang mở</span> @endif</td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </details>
                </section>
            @endif

            {{-- Tab: Lich su va hieu qua (cua ca du an) --}}
            <section class="sp-panel" data-sp-panel="history" data-social-history hidden>
                <section class="sp-card">
                    <div class="sp-card-head"><h3><i class="fas fa-history"></i> Lịch sử đăng của dự án</h3></div>
                    <div class="sp-table-wrap">
                        <table class="sp-table">
                            <thead><tr><th>Lúc tạo</th><th>Kênh</th><th>Gói</th><th>Cách đăng</th><th>Trạng thái</th><th>Thời gian</th><th>URL</th><th></th></tr></thead>
                            <tbody>
                            @forelse($social['history'] as $row)
                                @php $posting = \App\Services\Video\Social\SocialScreenService::posting($row); $rowZone = $row->channel?->timezone ?? 'UTC'; @endphp
                                <tr data-social-history-row="{{ $posting['id'] }}">
                                    <td>{{ $when($posting['created_at'], $rowZone) }}</td>
                                    <td><i class="{{ $platformIcon($row->channel?->platform) }}"></i> {{ $row->channel?->display_name ?? '—' }}</td>
                                    <td>{{ $social['formats'][$row->package?->format]['label'] ?? $row->package?->format }} · {{ $row->package?->language }} · R{{ $row->package?->revision }}</td>
                                    <td>{{ $posting['method'] === 'manual' ? 'Thủ công' : 'API' }} @if($posting['intent'] === 'repost') <small>(đăng thêm)</small> @endif</td>
                                    <td>{!! $tag($posting['status'], $posting['confirmed_by_person']) !!} @if($posting['error_message'])<br><small>{{ $posting['error_message'] }}</small>@endif</td>
                                    <td>@if($posting['published_at'])đăng {{ $when($posting['published_at'], $rowZone) }}@elseif($posting['scheduled_at'])hẹn {{ $when($posting['scheduled_at'], $rowZone) }}@else — @endif</td>
                                    <td>@if($posting['url'])<a href="{{ $posting['url'] }}" target="_blank" rel="noopener">mở</a>@else — @endif</td>
                                    <td><a class="sp-btn small" href="{{ route('video-projects.social-publishing', ['id' => $id, 'package' => $row->package_revision_id]) }}">Xem gói</a></td>
                                </tr>
                            @empty
                                <tr><td colspan="8"><div class="sp-empty" style="border:0"><i class="fas fa-inbox"></i><span>Chưa có lượt đăng nào.</span></div></td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                    @if($social['history']->hasPages())
                        <div class="sp-pages" data-social-pages>
                            @if($social['history']->currentPage() > 1)<a class="sp-btn small" href="{{ route('video-projects.social-publishing', array_filter(['id' => $id, 'package' => $sel['id'] ?? null, 'page' => $social['history']->currentPage() - 1])) }}#tab-history">← Trang trước</a>@endif
                            <span>Trang {{ $social['history']->currentPage() }} / {{ $social['history']->lastPage() }} · {{ $social['history']->total() }} lượt</span>
                            @if($social['history']->hasMorePages())<a class="sp-btn small" href="{{ route('video-projects.social-publishing', array_filter(['id' => $id, 'package' => $sel['id'] ?? null, 'page' => $social['history']->currentPage() + 1])) }}#tab-history">Trang sau →</a>@endif
                        </div>
                    @endif
                </section>

                <section class="sp-card">
                    <div class="sp-card-head"><h3><i class="fas fa-chart-line"></i> Hiệu quả</h3></div>
                    @unless($flags['analytics'])
                        <div class="sp-alert info" data-social-analytics-off><i class="fas fa-info-circle"></i><div>Đọc số liệu nền tảng đang tắt. Số liệu đã lưu trước đó vẫn hiển thị.</div></div>
                    @endunless
                    <p class="sp-sub" style="margin:0 0 12px">Mỗi số giữ tên của nền tảng cấp nó; "view" của Facebook và của YouTube là hai số khác nhau và không được cộng chung. Ô ghi "chưa có dữ liệu" nghĩa là nền tảng chưa cấp số đó — không phải 0.</p>
                    <div class="sp-col">
                        @forelse($social['dashboard']['postings'] as $posting)
                            <div class="sp-metric-card" data-social-metrics="{{ $posting['id'] }}">
                                <div class="sp-steps-row">
                                    <b>{{ $posting['channel'] }} · {{ $posting['format_label'] }} · {{ $posting['language'] }} · R{{ $posting['revision'] }}</b>
                                    <span class="sp-sub">{{ $posting['method'] === 'manual' ? 'đăng thủ công' : 'đăng qua API' }}{{ $posting['published_at'] ? ' · '.\Illuminate\Support\Carbon::parse($posting['published_at'])->format('Y-m-d H:i').' UTC' : '' }}</span>
                                    @if($posting['url'])<a href="{{ $posting['url'] }}" target="_blank" rel="noopener">mở bài</a>@endif
                                    @if($flags['analytics'] && $can['publish'] && $posting['readable'])
                                        <form method="POST" action="{{ route('video-projects.social-publication-metrics', [$id, $posting['id']]) }}" style="margin-left:auto">@csrf<button class="sp-btn small outline" type="submit"><i class="fas fa-chart-line"></i> Đọc số liệu</button></form>
                                    @elseif(! $posting['readable'])
                                        <span class="sp-sub">Lượt đăng tay không có ID nền tảng — không đọc số liệu tự động.</span>
                                    @endif
                                </div>
                                @if($posting['numbers'] !== [])
                                    <div class="sp-table-wrap">
                                        <table class="sp-table">
                                            <thead><tr><th>Số liệu</th><th>Giá trị</th><th>Khoảng đo</th><th>Lấy lúc</th><th>Nguồn</th></tr></thead>
                                            <tbody>
                                            @foreach($posting['numbers'] as $number)
                                                <tr data-social-metric="{{ $number['metric'] }}" data-social-metric-state="{{ $number['state'] }}">
                                                    <td>{{ $number['label'] }}</td>
                                                    <td>@if($number['state'] === 'available'){{ rtrim(rtrim(number_format((float) $number['value'], 2, '.', ','), '0'), '.') }} <small>{{ $number['unit'] }}</small>@elseif($number['state'] === 'not_provided')<span class="social-tag">chưa có dữ liệu</span>@else<span class="social-tag">chưa đọc</span>@endif</td>
                                                    <td>{{ $number['period'] ?? '—' }} @if($number['days']) <small>({{ $number['days'] }} ngày)</small> @endif</td>
                                                    <td>{{ $number['fetched_at'] ?? '—' }}</td>
                                                    <td><small>{{ $number['source'] ?? '—' }}</small></td>
                                                </tr>
                                            @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @endif
                            </div>
                        @empty
                            <div class="sp-empty"><i class="fas fa-chart-bar"></i><span>Chưa có bài nào đã đăng để đo.</span></div>
                        @endforelse
                    </div>

                    @if($social['dashboard']['groups'] !== [])
                        <p class="sp-section-title" style="margin-top:16px"><i class="fas fa-layer-group"></i> Theo nền tảng và định dạng <small class="sp-sub">(chỉ cộng các số đếm của cùng một nền tảng)</small></p>
                        <div class="sp-table-wrap" style="margin-top:8px">
                            <table class="sp-table" data-social-groups>
                                <thead><tr><th>Nhóm</th><th>Số bài</th><th>Tổng theo từng số liệu</th></tr></thead>
                                <tbody>
                                @foreach($social['dashboard']['groups'] as $group)
                                    <tr>
                                        <td>{{ $platforms[$group['platform']] ?? $group['platform'] }} · {{ $group['format_label'] }}</td>
                                        <td>{{ $group['postings'] }}</td>
                                        <td>@foreach($group['numbers'] as $number)<div>{{ $number['label'] }}: @if($number['sum'] === null)<span class="social-tag">chưa có dữ liệu</span>@else<b>{{ number_format($number['sum']) }}</b> <small>(từ {{ $number['with_data'] }}/{{ $group['postings'] }} bài có số)</small>@endif</div>@endforeach</td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                    @if($social['dashboard']['comparisons'] !== [])
                        <p class="sp-section-title" style="margin-top:16px"><i class="fas fa-balance-scale"></i> So sánh các lượt đăng của cùng một gói</p>
                        <p class="sp-sub" style="margin:4px 0 8px">Cùng kênh, định dạng, ngôn ngữ; chỉ so được khi số ngày đo tương đương — số ngày ghi ở từng bài phía trên.</p>
                        @foreach($social['dashboard']['comparisons'] as $rows)
                            <div class="sp-steps-row" data-social-comparison>
                                @foreach($rows as $row)
                                    <span class="sp-badge">{{ $row['channel'] }} · R{{ $row['revision'] }} · {{ $row['published_at'] ? \Illuminate\Support\Carbon::parse($row['published_at'])->format('Y-m-d') : '' }} · {{ collect($row['numbers'])->where('state', 'available')->count() }} số có dữ liệu</span>
                                @endforeach
                            </div>
                        @endforeach
                    @endif
                </section>
            </section>
        </main>

        {{-- Cot phai: mo phong bai dang --}}
        <aside class="sp-col sp-col-preview">
            @if($sel)
                <section class="sp-card sp-when-detached">
                    <div class="sp-card-head"><h3>Xem trước bài đăng</h3></div>
                    <div class="sp-empty"><i class="far fa-eye"></i><span>Lựa chọn trên thanh chưa có gói — chưa có gì để xem trước.</span></div>
                </section>
            @endif
            <section class="sp-card {{ $sel ? 'sp-when-attached' : '' }}" data-sp-live-preview>
                <div class="sp-card-head">
                    <h3>Xem trước bài đăng @if($sel) <span class="sp-badge" data-sp-live-state data-saved-label="Bản đã lưu R{{ $sel['revision'] }}">Bản đã lưu R{{ $sel['revision'] }}</span> @endif</h3>
                    @if($sel)
                        <div class="sp-switch">
                            @foreach($platforms as $platform => $platformName)
                                @if($platform === $sel['rules']['platform'])
                                    <span class="on"><i class="{{ $platformIcon($platform) }}"></i> {{ $platformName }}</span>
                                @else
                                    @php $sibling = collect($social['packages'])->first(static fn (array $row): bool => $row['platform'] === $platform && $row['language'] === $sel['language']); @endphp
                                    @if($sibling)
                                        <a href="{{ route('video-projects.social-publishing', ['id' => $id, 'package' => $sibling['id']]) }}" title="Mở gói {{ $platformName }} cùng ngôn ngữ"><i class="{{ $platformIcon($platform) }}"></i> {{ $platformName }}</a>
                                    @else
                                        <span class="off" title="Chưa có gói {{ $platformName }} cho ngôn ngữ này"><i class="{{ $platformIcon($platform) }}"></i> {{ $platformName }}</span>
                                    @endif
                                @endif
                            @endforeach
                        </div>
                    @endif
                </div>
                @if(! $sel)
                    <div class="sp-empty"><i class="far fa-eye"></i><span>Mở một gói để xem trước bài đăng.</span></div>
                @else
                    @php
                        $doc = $sel['document'];
                        $rules = $sel['rules'];
                        $name = (string) ($sel['channel']['display_name'] ?? '');
                        $initial = mb_strtoupper(mb_substr($name, 0, 1)) ?: '?';
                        $postText = (string) $doc['rendered']['text'];
                        $media = $sel['film']['media'];
                    @endphp
                    @if($rules['platform'] === 'youtube')
                        <article class="sp-post" data-sp-post="youtube">
                            <div class="sp-media">
                                @if($sel['artwork'])
                                    <img src="{{ route('video-projects.social-cover', [$id, $sel['artwork']['artifact_id']]) }}" alt="Cover">
                                @else
                                    <video src="{{ route('video-projects.final-file', [$id, $sel['film']['final_id']]) }}" controls preload="metadata" playsinline></video>
                                @endif
                                <span class="sp-media-tag tr">{{ $ratio($media['width'] ?? null, $media['height'] ?? null) }}</span>
                                <span class="sp-media-tag bl">{{ $mmss($media['duration_ms'] ?? null) }}</span>
                            </div>
                            <div class="sp-yt-title" data-sp-live="title" data-sp-empty="(chưa có tiêu đề)">{{ $doc['rendered']['title'] ?? '(chưa có tiêu đề)' }}</div>
                            <div class="sp-yt-channel"><span class="sp-avatar small">{{ $initial }}</span><div><b>{{ $name }}</b><br><small class="sp-sub">Bản xem trước · chưa đăng<span data-sp-live="privacy">{{ $doc['metadata']['privacy'] ? ' · '.$doc['metadata']['privacy'] : '' }}</span></small></div></div>
                            <div class="sp-yt-desc">
                                <div class="sp-post-text" data-sp-live="text" data-sp-empty="(chưa có mô tả)">{!! $postText === '' ? '<span class="sp-sub">(chưa có mô tả)</span>' : $highlight($postText) !!}</div>
                                <div class="sp-sub" style="margin-top:8px" data-sp-live="tags" @if($doc['rendered']['tags'] === []) hidden @endif>Tags: {{ implode(', ', $doc['rendered']['tags']) }}</div>
                            </div>
                        </article>
                    @else
                        <article class="sp-post" data-sp-post="facebook">
                            <header class="sp-post-head">
                                <span class="sp-avatar">{{ $initial }}</span>
                                <div><b>{{ $name }}</b><small>Bản xem trước · chưa đăng <i class="fas fa-globe-americas"></i></small></div>
                                <i class="fas fa-ellipsis-h"></i>
                            </header>
                            <div class="sp-post-body">
                                <h4 data-social-rendered-title data-sp-live="title" data-sp-empty="" @if(! $doc['rendered']['title']) hidden @endif>{{ $doc['rendered']['title'] }}</h4>
                                <div class="sp-post-text" data-sp-live="text" data-sp-empty="(chưa có nội dung)">{!! $postText === '' ? '<span class="sp-sub">(chưa có nội dung)</span>' : $highlight($postText) !!}</div>
                            </div>
                            <div class="sp-media">
                                @if($sel['artwork'])
                                    <img src="{{ route('video-projects.social-cover', [$id, $sel['artwork']['artifact_id']]) }}" alt="Cover">
                                @else
                                    <video src="{{ route('video-projects.final-file', [$id, $sel['film']['final_id']]) }}" controls preload="metadata" playsinline></video>
                                @endif
                                <span class="sp-media-tag tr">{{ $ratio($media['width'] ?? null, $media['height'] ?? null) }}</span>
                                <span class="sp-media-tag bl">{{ $mmss($media['duration_ms'] ?? null) }}</span>
                            </div>
                            <footer class="sp-post-foot">
                                <span><i class="far fa-thumbs-up"></i>Thích</span><span><i class="far fa-comment"></i>Bình luận</span><span><i class="fas fa-share"></i>Chia sẻ</span>
                            </footer>
                        </article>
                    @endif
                    <div class="sp-meta-chips">
                        {{-- Theo form khi dang chinh; cover va trang thai la cua ban da luu (khong sua o tab Noi dung). --}}
                        <span class="sp-badge {{ $doc['disclosure']['synthetic_media'] ? 'info' : '' }}" data-sp-live="synthetic">Khai báo AI: {{ $doc['disclosure']['synthetic_media'] ? 'có' : 'không' }}</span>
                        <span class="sp-badge {{ $sel['artwork'] ? 'ok' : 'warn' }}" title="Của bản đã lưu R{{ $sel['revision'] }}">Cover: {{ $sel['artwork'] ? 'đã chọn' : 'chưa có' }}</span>
                        <span class="sp-badge" title="Của bản đã lưu R{{ $sel['revision'] }}">R{{ $sel['revision'] }} · {{ $revisionLabel[$sel['status']] ?? $sel['status'] }}</span>
                    </div>
                    <p class="sp-sub" style="margin:8px 0 0" data-sp-dirty-warning hidden>Nội dung, tiêu đề, khai báo AI và chế độ hiển thị ở trên là của bản đang chỉnh; cover và trạng thái là của bản đã lưu R{{ $sel['revision'] }}.</p>
                    <p class="sp-sub" style="margin:10px 0 0">Mô phỏng bố cục để đọc lại — không giống tuyệt đối giao diện nền tảng. Khi bạn đang sửa ở tab Nội dung, khung này hiện <b>bản đang chỉnh (chưa lưu)</b>; duyệt và đăng luôn dùng bản đã lưu. Số lượt thích, bình luận, chia sẻ thật nằm ở tab Lịch sử &amp; Hiệu quả sau khi đăng.</p>
                @endif
            </section>
        </aside>
    </div>

    {{-- Hop thoai: ho so kenh va ket noi --}}
    <div class="sp-modal" id="sp-modal-channels" hidden>
        <div class="sp-modal-box" role="dialog" aria-modal="true" aria-labelledby="sp-modal-channels-title">
            <header><h3 id="sp-modal-channels-title">Hồ sơ kênh và kết nối API</h3><button type="button" class="sp-icon-btn" data-sp-close aria-label="Đóng"><i class="fas fa-times"></i></button></header>
            <section data-social-channels>
                <div class="sp-table-wrap">
                    <table class="sp-table">
                        <thead><tr><th>Kênh</th><th>Nền tảng</th><th>ID nền tảng</th><th>Ngôn ngữ · múi giờ</th><th>Trạng thái</th></tr></thead>
                        <tbody>
                        @forelse($social['channels'] as $channel)
                            <tr data-social-channel="{{ $channel['id'] }}">
                                <td><b>{{ $channel['display_name'] }}</b> @if($channel['url'])<br><a href="{{ $channel['url'] }}" target="_blank" rel="noopener">{{ $channel['url'] }}</a>@endif
                                    @if($can['channels'] && $channel['status'] === 'active')
                                        <details data-social-channel-edit="{{ $channel['id'] }}">
                                            <summary><small>Sửa hồ sơ</small></summary>
                                            <form method="POST" action="{{ route('video-projects.social-channel-update', [$id, $channel['id']]) }}" class="social-form">
                                                @csrf
                                                <input type="hidden" name="platform" value="{{ $channel['platform'] }}">
                                                <label>Tên hiển thị<input type="text" name="display_name" value="{{ $channel['display_name'] }}" required maxlength="191"></label>
                                                <label>Page ID / Channel ID <small>(đổi ID thì hồ sơ trở về chưa xác minh)</small><input type="text" name="external_id" value="{{ $channel['external_id'] }}" maxlength="191"></label>
                                                <label>URL kênh<input type="url" name="url" value="{{ $channel['url'] }}" maxlength="500"></label>
                                                <div class="social-inline">
                                                    <label>Ngôn ngữ<select name="language">@foreach($languages as $code => $languageName)<option value="{{ $code }}" @selected($channel['language'] === $code)>{{ $languageName }}</option>@endforeach</select></label>
                                                    <label>Múi giờ<input type="text" name="timezone" value="{{ $channel['timezone'] }}" required></label>
                                                </div>
                                                <label>Đối tượng khán giả<textarea name="audience" maxlength="2000">{{ $channel['audience'] }}</textarea></label>
                                                <label>Giọng điệu thương hiệu<textarea name="brand_voice" maxlength="2000">{{ $channel['brand_voice'] }}</textarea></label>
                                                <label>Mục tiêu mặc định<input type="text" name="default_goal" value="{{ $channel['default_goal'] }}" maxlength="255"></label>
                                                <label>CTA mặc định<input type="text" name="default_cta" value="{{ $channel['default_cta'] }}" maxlength="1000"></label>
                                                <label>Liên kết mặc định<input type="url" name="default_link" value="{{ $channel['default_link'] }}" maxlength="500"></label>
                                                @foreach($languages as $code => $languageName)
                                                    <label>Câu công bố — {{ $languageName }}<input type="text" name="disclosure_texts[{{ $code }}]" value="{{ $channel['disclosure']['texts'][$code] ?? '' }}" maxlength="500"></label>
                                                @endforeach
                                                <label class="social-check"><input type="hidden" name="synthetic_media" value="0"><input type="checkbox" name="synthetic_media" value="1" @checked($channel['disclosure']['synthetic_media'] ?? false)> Mặc định bật khai báo nội dung AI của nền tảng</label>
                                                <div><button class="sp-btn primary small" type="submit">Lưu hồ sơ (áp dụng cho gói mới)</button></div>
                                            </form>
                                            <form method="POST" action="{{ route('video-projects.social-channel-archive', [$id, $channel['id']]) }}">@csrf<button class="sp-btn danger small" type="submit">Lưu trữ hồ sơ</button></form>
                                        </details>
                                    @endif
                                </td>
                                <td><i class="{{ $platformIcon($channel['platform']) }}"></i> {{ $platforms[$channel['platform']] ?? $channel['platform'] }}</td>
                                <td>{{ $channel['external_id'] ?? '—' }}</td>
                                <td>{{ $channel['language'] }} · {{ $channel['timezone'] }}</td>
                                <td>
                                    @if($channel['status'] !== 'active')<span class="social-tag">đã lưu trữ</span>
                                    @elseif($channel['verified'])<span class="social-tag ok" data-social-verified>Đã xác minh qua API</span>
                                    @else<span class="social-tag warn" data-social-declared>Người dùng khai báo</span>@endif
                                    @if($channel['connection'])<br><small>{{ $channel['connection']['label'] }} ({{ $channel['connection']['status'] }})</small>@endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5">Chưa có hồ sơ kênh nào. Tạo hồ sơ để viết và xuất gói; chưa cần kết nối API.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>

                @if($can['channels'])
                    <details data-social-channel-form style="margin-top:12px">
                        <summary class="sp-btn outline small"><i class="fas fa-plus"></i> Thêm hồ sơ kênh</summary>
                        <form method="POST" action="{{ route('video-projects.social-channel-store', $id) }}" class="social-form">
                            @csrf
                            <div class="social-inline">
                                <label>Nền tảng<select name="platform">@foreach($platforms as $code => $platformName)<option value="{{ $code }}">{{ $platformName }}</option>@endforeach</select></label>
                                <label>Tên hiển thị<input type="text" name="display_name" required maxlength="191"></label>
                                <label>Page ID / Channel ID <small>(để trống nếu chưa biết)</small><input type="text" name="external_id" maxlength="191"></label>
                            </div>
                            <div class="social-inline">
                                <label>URL kênh<input type="url" name="url" maxlength="500"></label>
                                <label>Ngôn ngữ mặc định<select name="language">@foreach($languages as $code => $languageName)<option value="{{ $code }}">{{ $languageName }}</option>@endforeach</select></label>
                                <label>Múi giờ<input type="text" name="timezone" value="Asia/Ho_Chi_Minh" required></label>
                            </div>
                            <label>Đối tượng khán giả<textarea name="audience" maxlength="2000"></textarea></label>
                            <label>Giọng điệu thương hiệu<textarea name="brand_voice" maxlength="2000"></textarea></label>
                            <div class="social-inline">
                                <label>Mục tiêu nội dung mặc định<input type="text" name="default_goal" maxlength="255"></label>
                                <label>Liên kết mặc định<input type="url" name="default_link" maxlength="500"></label>
                            </div>
                            <label>CTA mặc định<input type="text" name="default_cta" maxlength="1000"></label>
                            <b>Quy tắc công bố nội dung AI</b>
                            @foreach($languages as $code => $languageName)
                                <label>Câu công bố trong caption — {{ $languageName }} <small>(để trống nếu kênh không chèn câu công bố; mỗi gói vẫn sửa được)</small><input type="text" name="disclosure_texts[{{ $code }}]" maxlength="500"></label>
                            @endforeach
                            <label class="social-check"><input type="hidden" name="synthetic_media" value="0"><input type="checkbox" name="synthetic_media" value="1"> Mặc định bật khai báo "nội dung tổng hợp/AI" của nền tảng</label>
                            <div><button class="sp-btn primary small" type="submit"><i class="far fa-save"></i> Lưu hồ sơ kênh</button></div>
                        </form>
                    </details>
                @endif
            </section>

            <section>
                <p class="sp-section-title"><i class="fas fa-plug"></i> Kết nối API</p>
                @forelse($social['connections'] as $connection)
                    <div class="social-row" data-social-connection="{{ $connection['id'] }}">
                        <b><i class="{{ $platformIcon($connection['platform']) }}"></i> {{ $connection['label'] }}</b>
                        <span class="social-tag {{ $connection['status'] === 'active' ? 'ok' : 'bad' }}">{{ $connection['status'] }}</span>
                        <small>{{ implode(', ', array_map(static fn ($account) => $account['name'].' ['.$account['id'].']', $connection['accounts'])) ?: 'không có trang/kênh' }}{{ $connection['token_expires_at'] ? ' · token đến '.$connection['token_expires_at'].' UTC' : '' }}</small>
                        @if($connection['last_error'])<small>· {{ $connection['last_error'] }}</small>@endif
                        @if($can['connect'] && $connection['status'] !== 'revoked')
                            <form method="POST" action="{{ route('video-projects.social-disconnect', [$id, $connection['id']]) }}">@csrf<button class="sp-btn danger small" type="submit">Ngắt kết nối</button></form>
                        @endif
                    </div>
                @empty
                    <p class="sp-sub">Chưa có kết nối nào. Hồ sơ kênh vẫn dùng được để viết, duyệt và xuất gói đăng tay.</p>
                @endforelse
                @if($can['connect'])
                    <div class="sp-steps-row" style="margin-top:8px">
                        @foreach($platforms as $code => $platformName)
                            <a class="sp-btn small" href="{{ route('video-projects.social-connect', [$id, $code]) }}" data-social-connect="{{ $code }}"><i class="{{ $platformIcon($code) }}"></i> Kết nối {{ $platformName }}</a>
                        @endforeach
                    </div>
                    <p class="sp-sub">Kết nối xác minh hồ sơ theo đúng ID nền tảng — không ghép theo tên kênh. Token chỉ nằm trong kết nối, không vào prompt, manifest hay log.</p>
                @endif
            </section>
        </div>
    </div>

    {{-- Hop thoai: goi cua du an --}}
    <div class="sp-modal" id="sp-modal-packages" hidden>
        <div class="sp-modal-box" role="dialog" aria-modal="true" aria-labelledby="sp-modal-packages-title">
            <header><h3 id="sp-modal-packages-title">Gói nội dung của dự án</h3><button type="button" class="sp-icon-btn" data-sp-close aria-label="Đóng"><i class="fas fa-times"></i></button></header>
            <div class="sp-table-wrap">
                <table class="sp-table" data-social-packages>
                    <thead><tr><th>Kênh</th><th>Định dạng</th><th>Ngôn ngữ</th><th>Bản mới nhất</th><th>Ghi chú</th><th></th></tr></thead>
                    <tbody>
                    @forelse($social['packages'] as $row)
                        <tr data-social-package="{{ $row['id'] }}" class="{{ ($sel['group'] ?? null) === $row['group'] ? 'on' : '' }}">
                            <td><i class="{{ $platformIcon($row['platform']) }}"></i> {{ $row['channel'] }}</td>
                            <td>{{ $social['formats'][$row['format']]['label'] ?? $row['format'] }}</td>
                            <td>{{ $row['language'] }}</td>
                            <td>R{{ $row['revision'] }} {!! $tag($row['status']) !!} @if($row['approved_revisions'] !== [] && $row['status'] !== 'approved')<br><small>đã duyệt: R{{ implode(', R', $row['approved_revisions']) }}</small>@endif</td>
                            <td>
                                @if($row['film_changed'])<span class="social-tag bad" data-social-stale="film">tham chiếu bản phim trước</span>@endif
                                @if($row['policy_changed'])<span class="social-tag warn" data-social-stale="policy">chính sách đã đổi</span>@endif
                                @if($row['channel_changed'])<span class="social-tag warn" data-social-stale="channel">hồ sơ kênh đã đổi</span>@endif
                            </td>
                            <td><a class="sp-btn small" href="{{ route('video-projects.social-publishing', ['id' => $id, 'package' => $row['id']]) }}">Mở</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="6">Chưa có gói nào.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Hop thoai: chi tiet nguon va chinh sach nen tang --}}
    <div class="sp-modal" id="sp-modal-source" hidden>
        <div class="sp-modal-box" role="dialog" aria-modal="true" aria-labelledby="sp-modal-source-title">
            <header><h3 id="sp-modal-source-title">Chi tiết nguồn và chính sách nền tảng</h3><button type="button" class="sp-icon-btn" data-sp-close aria-label="Đóng"><i class="fas fa-times"></i></button></header>
            @if($film && $social['source'])
                <section>
                    <p class="sp-section-title"><i class="fas fa-film"></i> Phim đã duyệt</p>
                    <dl class="sp-kv" style="margin-top:8px">
                        <dt>Bản final</dt><dd>{{ $film['final_id'] }} · revision {{ $film['revision'] }} · {{ $film['shots'] }} shot{{ $film['purpose'] ? ' · '.$film['purpose'] : '' }}</dd>
                        <dt>Hash file</dt><dd>{{ $film['sha256'] }}</dd>
                        <dt>Đo trên file</dt><dd>{{ $film['media']['width'] }}×{{ $film['media']['height'] }} · {{ $film['media']['fps'] ?? '?' }} fps · {{ $clock($film['media']['duration_ms']) }} · {{ $film['media']['container'] }} · {{ $film['media']['has_audio'] ? 'có âm thanh' : 'không có âm thanh' }} · {{ number_format($film['media']['bytes'] / 1048576, 1) }} MB</dd>
                        <dt>Hash nguồn</dt><dd>{{ $social['source']['hash'] }}</dd>
                        <dt>Nguồn ghim</dt><dd>{{ $social['source']['refs'] }} mục dẫn nguồn · {{ count($social['source']['assets']) }} ảnh/frame làm cover</dd>
                    </dl>
                </section>
            @endif
            @if($social['policy'])
                <section>
                    <p class="sp-section-title"><i class="fas fa-ruler-combined"></i> Phim này dùng được cho định dạng nào</p>
                    <div class="sp-col" style="gap:8px;margin-top:8px">
                        @foreach($social['formats'] as $key => $rules)
                            <div class="social-row" data-social-format="{{ $key }}">
                                <b><i class="{{ $platformIcon($rules['platform']) }}"></i> {{ $rules['label'] }}</b>
                                @if($film === null)
                                    <span class="social-tag">chưa có phim</span>
                                @elseif($rules['media_problems'] === [])
                                    <span class="social-tag ok">file phù hợp</span>
                                @else
                                    <span class="social-tag bad">cần bản xuất khác</span>
                                    <small>{{ implode(' ', $rules['media_problems']) }} Tạo bản xuất phù hợp ở Final Composition (Phase 11).</small>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    <p class="sp-sub">Chính sách nền tảng revision {{ $social['policy']['phase_revision'] }}, đọc tài liệu ngày {{ $social['policy']['checked_on'] }}. Giới hạn của tài khoản và quyền kết nối do nền tảng trả lời lúc chuẩn bị đăng.</p>
                </section>
            @endif
        </div>
    </div>

    {{-- Hop thoai: huong dan --}}
    <div class="sp-modal" id="sp-modal-help" hidden>
        <div class="sp-modal-box" role="dialog" aria-modal="true" aria-labelledby="sp-modal-help-title" style="width:min(720px, 100%)">
            <header><h3 id="sp-modal-help-title">Hướng dẫn Social Publishing</h3><button type="button" class="sp-icon-btn" data-sp-close aria-label="Đóng"><i class="fas fa-times"></i></button></header>
            <ol class="social-list" style="font-size:14px;display:grid;gap:8px">
                <li><b>Chọn gói</b> ở thanh trên: mỗi gói là một kênh, một định dạng và một ngôn ngữ. Hồ sơ kênh và kết nối API nằm trong menu "…".</li>
                <li><b>Nội dung</b>: viết tay, hoặc bấm "Tạo nội dung (AI)" — có tính phí. Mỗi lần lưu tạo một revision nháp mới; bản đã duyệt không bao giờ bị sửa đè.</li>
                <li><b>Cover</b>: trích một frame của phim đã duyệt rồi crop, viết chữ bằng FFmpeg — không dùng AI vẽ lại hình.</li>
                <li><b>Xem trước</b>: đọc lại nguyên văn những gì sẽ đăng, kể cả câu công bố AI.</li>
                <li><b>Duyệt &amp; Đăng</b>: duyệt không phải là đăng; xuất gói không phải là đã đăng. Đăng tay rồi ghi nhận, hoặc đăng qua API khi kết nối và cờ đăng đã bật.</li>
                <li><b>Lịch sử &amp; Hiệu quả</b>: mọi lượt đăng và số liệu nền tảng cấp — số nền tảng không cấp là "chưa có dữ liệu", không phải 0.</li>
            </ol>
        </div>
    </div>
</div>
@endsection

@section('script')
<script src="{{ asset('assets/js/social-publishing.js') }}?v={{ filemtime(public_path('assets/js/social-publishing.js')) }}"></script>
@endsection
