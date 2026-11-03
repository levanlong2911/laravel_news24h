@extends('layouts.base', ['title' => 'Locations & Sets'])
@section('title', 'Locations & Sets')

@section('css')
<link rel="stylesheet" href="{{ asset('assets/css/video-producer.css') }}?v={{ filemtime(public_path('assets/css/video-producer.css')) }}">
@endsection

@section('content')
<div class="container-fluid vp" id="locationsPage">
    <div id="locationsFlash" hidden data-ajax-part data-ajax-flash data-success="{{ session('success') }}" data-error="{{ session('error') ?? $errors->first() }}"></div>

    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0 pl-3">
                @foreach($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @php
        $shown = $step['shown'];
        $check = $shown['check'] ?? null;
        $plan = $shown['plan'] ?? null;
        $spaceLabel = static fn (array $space): string => $space['id'].($space['state'] !== null ? ' · '.$space['state'] : '');
        $refs = collect($step['references']);
        $readyCount = $refs->where('problem', null)->count();
        $media = $step['media'];
        $pinnedShown = $shown !== null && $shown['text_approved'] && $refs->isNotEmpty();
        $pinnedRows = $refs->keyBy('request.id');
        $gridRows = collect($shown['images'] ?? [])->map(fn (array $row): array => $pinnedShown && $pinnedRows->has($row['request']['id'])
            ? ['prompt' => $row['prompt'], 'sha256' => $row['sha256']] + $pinnedRows->get($row['request']['id'])
            : $row + ['cells' => $shown['cells'][$row['request']['id']] ?? [], 'chosen' => null, 'problem' => null, 'can_render' => false]);
        $renderable = $shown !== null && $shown['renderable'] && ! $media['error'] && $media['default'] !== null;
        $kinds = ['location' => 'địa điểm ngoài tàu', 'interior' => 'phòng nội thất', 'exterior' => 'khu ngoài trời trên tàu'];
        $sourceTypes = ['script_id' => 'kịch bản ghi ID', 'mapped_id' => 'khớp ID thiết kế', 'location' => 'địa điểm của bộ này'];
        $quoteOf = static fn (?array $evidence): string => $evidence === null ? 'giả định' : $evidence['beat'].($evidence['tour_stop'] === null ? '' : ' #'.$evidence['tour_stop']).' '.$evidence['field'].': "'.$evidence['quote'].'"';
    @endphp

    <div class="va-page">
        <div>
            <h1>Locations & Sets</h1>
            <p>Phase 5 — địa điểm ngoài tàu, phòng nội thất theo trạng thái thi công và khu ngoài trời trên tàu, lập từ kịch bản đã duyệt; mỗi ảnh bối cảnh dựng riêng từ prompt, không ảnh nguồn</p>
            <p style="margin:0;color:var(--vp-dim)">
                Nguồn: kịch bản đã duyệt và bản thiết kế mà kịch bản đã ghim
                @if($step['prompt'] !== null) &middot; {{ $step['prompt'] }} &middot; {{ $step['model'] }} (effort mặc định {{ $step['effort'] }}) @endif
            </p>
        </div>
        <div style="display:flex;gap:8px;align-items:center" id="locationsTools" data-ajax-part>
            @if($step['text_error'] === null && $refs->isNotEmpty())
                <span class="va-tag {{ $readyCount === $refs->count() ? 'ok' : 'mute' }}">{{ $readyCount }} / {{ $refs->count() }} ĐÃ DUYỆT</span>
            @endif
            <a class="vp-btn" href="{{ route('video-projects.anchor', $id) }}#scriptPanel">← Kịch bản</a>
            @if($scenesSupported && $step['pinned'] !== null)<a class="vp-btn" href="{{ route('video-projects.scene', $id) }}">Phase 6 — Scene →</a>@endif
        </div>
    </div>

    <div class="vp-panel" id="locationsText">
        <div class="va-head">
            <span class="n">1</span>
            <b>BỐI CẢNH VÀ PROMPT</b>
            <em>địa điểm LOC, bối cảnh từ LOC · INT · EXT và yêu cầu ảnh bối cảnh kèm prompt đầy đủ</em>
            <span class="grow"></span>
            @if($step['running'])
                <button class="vp-btn sm" disabled>Đang tạo…</button>
            @elseif($step['stuck'])
                <form method="POST" action="{{ route('video-projects.locations-reset', $id) }}" data-ajax-whole>
                    @csrf
                    <button class="vp-btn sm dg">Reset lượt bị kẹt</button>
                </form>
            @elseif($step['can_run'] || $step['can_rewrite'])
                <form method="POST" action="{{ route('video-projects.locations-write', $id) }}" id="locationsWriteForm" data-modal="confirmLocationsWrite" data-ajax-whole onsubmit="return vpLockForm(this)">
                    @csrf
                    <input type="hidden" name="operation_id" value="{{ $step['operation_id'] }}">
                    @if($step['can_rewrite'])<input type="hidden" name="rewrite" value="1">@endif
                </form>
                <select class="ctl" name="effort" form="locationsWriteForm" title="Effort của riêng lượt này">
                    @foreach($step['efforts'] as $effort)
                        <option value="{{ $effort }}" @selected($effort === $step['effort'])>effort {{ $effort }}{{ $effort === $step['effort'] ? ' (mặc định)' : '' }}</option>
                    @endforeach
                </select>
                <button type="button" class="vp-btn sm pri" data-toggle="modal" data-target="#confirmLocationsWrite" data-busy="Đang tạo…">{{ $step['can_rewrite'] ? 'Tạo lại địa điểm và bối cảnh' : 'Tạo địa điểm và bối cảnh' }}</button>
                @include('modal.confirm_action', [
                    'id' => 'confirmLocationsWrite',
                    'form' => 'locationsWriteForm',
                    'content' => 'Gọi '.$step['model'].' (effort chọn ở ô bên cạnh) một lần để tạo địa điểm ngoài tàu và bộ bối cảnh dùng chung từ kịch bản đã duyệt — tác vụ này tính tiền. Tạo xong không tự render và không thay bộ đang dùng.',
                    'detail' => 'Tối đa '.number_format((int) $step['max_tokens'], 0, ',', '.').' token đầu ra, chờ tối đa '.$step['timeout_minutes'].' phút; trả về dạng stream, tiến độ xem được khi tải lại trang ở tab khác.',
                ])
            @else
                <button class="vp-btn sm" disabled title="{{ $step['run_error'] }}">Tạo địa điểm và bối cảnh</button>
            @endif
        </div>

        <div class="va-body">
            <div class="va-brief" id="locationsBrief" data-ajax-part>
                @if($step['run_error'] !== null)
                    <div class="line"><span class="k">Chưa tạo được</span><span class="v" style="color:var(--vp-red)">{{ $step['run_error'] }}</span></div>
                @endif
                @include('video-projects.partials.stream-state', ['stream' => $step['stream']])
                @if($step['error'] !== null)
                    <div class="line"><span class="k">Lượt gần nhất lỗi</span><span class="v" style="color:var(--vp-red)">{{ $step['error'] }}</span></div>
                @endif
                @if($step['text_error'] !== null)
                    <div class="line"><span class="k">Bộ đang dùng</span><span class="v" style="color:var(--vp-red)">{{ $step['text_error'] }}</span></div>
                @endif
                @if($step['history'] !== [])
                    <div class="line"><span class="k">Các lượt</span><span class="v">
                        @foreach($step['history'] as $row)
                            <a href="{{ route('video-projects.locations', ['id' => $id, 'stage' => $row['id']]) }}">rev {{ $row['revision'] }}</a>
                            {{ $row['status'] }}{{ $row['effort'] ? ' · effort '.$row['effort'] : '' }}{{ $row['verdict'] ? ' · '.$row['verdict'] : '' }}{{ $row['text_approved'] ? ' · đang dùng' : '' }}@if(! $loop->last) &middot; @endif
                        @endforeach
                    </span></div>
                @endif
            </div>

            @if($shown !== null)
                <div class="va-lbl" id="locationsRev" data-ajax-part>
                    BẢN REV {{ $shown['revision'] }}
                    @if($shown['text_approved'])<span class="va-tag ok">ĐANG DÙNG</span>@endif
                    @if($check !== null)<span class="va-tag {{ $check['verdict'] === 'PASS' ? 'ok' : 'dg' }}">RULE: {{ $check['verdict'] }}</span><span class="va-tag mute">VISION: not_run</span>@if($shown['legacy'] === null)<span class="va-tag mute">KIỂM NGHĨA: {{ $step['semantic_review'] === 'manual' ? 'người duyệt' : $step['semantic_review'] }}</span>@endif @endif
                    @if($shown['cost_usd'] !== null)<em>{{ $shown['tokens']['in'] }}/{{ $shown['tokens']['out'] }} token · ${{ number_format($shown['cost_usd'], 3) }}</em>@endif
                </div>
                @if($shown['integrity_error'] !== null)
                    <div class="va-lbl" style="font-weight:400;color:var(--vp-red)">Không dùng được bản này: {{ $shown['integrity_error'] }}</div>
                @endif
                @if($check !== null)
                    @if($check['error'] !== null)<div class="va-lbl" style="font-weight:400;color:var(--vp-red)">{{ $check['error'] }}</div>@endif
                    @foreach(array_merge($check['schema'], $check['issues']) as $issue)
                        <div class="va-lbl" style="font-weight:400;color:var(--vp-red)">RULE: {{ $issue }}</div>
                    @endforeach
                    @foreach($check['warnings'] as $warning)
                        <div class="va-lbl" style="font-weight:400;color:var(--vp-amber-fg)">WARN: {{ $warning }}</div>
                    @endforeach
                @endif
                @if($shown['legacy'] !== null)
                    <div class="va-lbl" style="font-weight:400;color:var(--vp-dim)">Bản theo định dạng cũ {{ $shown['legacy'] }} — hiện kết quả kiểm lúc chạy và bảng địa điểm, chỉ để xem và truy vết; không render. Bấm "Tạo địa điểm và bối cảnh" để có bản mới; bản cũ, ảnh cũ và quyết định cũ vẫn giữ.</div>
                @endif
                @if($shown['flags'] !== [])
                    <div class="va-lbl">GHI CHÚ CỦA MODEL (FLAGS — chưa được hệ thống kiểm chứng)</div>
                    @foreach($shown['flags'] as $flag)
                        <div class="va-lbl" style="font-weight:400">· {{ $flag }}</div>
                    @endforeach
                @endif

                @if($shown['locations'] !== [])
                    <div class="va-lbl">ĐỊA ĐIỂM</div>
                    <div class="vs-script-scroll">
                        <table class="vr-needs">
                            <thead><tr><th>ID</th><th>Loại</th><th>Không gian</th><th>Kích thước (m)</th><th>Chứa</th><th>Bề mặt · thiết bị</th><th>Giả định</th></tr></thead>
                            <tbody>
                                @foreach($shown['locations'] as $location)
                                    @php $fit = is_array($location['fit'] ?? null) ? $location['fit'] : null; @endphp
                                    <tr>
                                        <td><b>{{ $location['id'] ?? '—' }}</b><br><em>{{ $location['name'] ?? '' }}</em></td>
                                        <td>{{ $location['kind'] ?? '—' }}</td>
                                        <td>{{ $location['space'] ?? '—' }}</td>
                                        <td>@foreach(['length_m' => 'dài', 'width_m' => 'rộng', 'height_m' => 'cao'] as $axis => $word){{ $word }} {{ $location['dimensions'][$axis]['value'] ?? '—' }} <em>({{ $location['dimensions'][$axis]['basis'] ?? '—' }})</em><br>@endforeach</td>
                                        <td>@if($fit !== null)<b>{{ $fit['subject'] }}</b>{{ $fit['beats'] !== [] ? ' · '.implode(', ', $fit['beats']) : '' }}<br>
                                            <em>{{ $fit['footprint'] }} · trên đầu {{ $fit['overhead'] }} · thông thủy {{ $fit['clear_height_m']['value'] ?? '—' }} ({{ $fit['clear_height_m']['basis'] }})</em><br>
                                            {{ $fit['basis'] }}@else — @endif</td>
                                        <td>{{ implode(', ', (array) ($location['surfaces'] ?? [])) }}@if(($location['fixed_equipment'] ?? []) !== [])<br><em>{{ implode(', ', (array) $location['fixed_equipment']) }}</em>@endif</td>
                                        <td>@foreach((array) ($location['assumptions'] ?? []) as $assumption){{ $assumption['subject'] }}: {{ $assumption['value'] }} — {{ $assumption['reason'] }}<br>@endforeach</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @if(($plan['conflicts'] ?? []) !== [])
                    <div class="va-lbl">XUNG ĐỘT KỊCH BẢN / THIẾT KẾ — CHẶN BỐI CẢNH LIÊN QUAN</div>
                    <div class="vs-script-scroll">
                        <table class="vr-needs">
                            <thead><tr><th>Beat</th><th>Bối cảnh</th><th>Nội dung lệch</th><th>Thiết kế</th><th>Kịch bản</th><th>Ghi chú</th></tr></thead>
                            <tbody>
                                @foreach($plan['conflicts'] as $conflict)
                                    <tr><td><b>{{ $conflict['beat'] ?? '—' }}</b></td><td>{{ $conflict['background'] ?? '—' }}</td><td>{{ $conflict['field'] }}</td><td>{{ $conflict['design'] }}</td><td>{{ $conflict['script'] }}</td><td>{{ $conflict['note'] }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
                @if(($plan['unresolved'] ?? []) !== [])
                    <div class="va-lbl">MỤC CHƯA XÁC ĐỊNH — CHƯA BÀN GIAO ĐƯỢC</div>
                    @foreach($plan['unresolved'] as $entry)
                        <div class="va-lbl" style="font-weight:400;color:var(--vp-red)">· {{ $entry['beat'] }}{{ $entry['tour_stop'] !== null ? ' #'.$entry['tour_stop'] : '' }}{{ $entry['space'] !== null ? ' · '.$entry['space']['kind'].' '.$spaceLabel($entry['space']) : '' }} {{ $entry['part'] }}: {{ $entry['text'] }} — {{ $entry['reason'] }}{{ $entry['candidates'] !== [] ? ' · ứng viên '.implode(', ', $entry['candidates']) : '' }}</div>
                    @endforeach
                @endif
                @if(($plan['unplated'] ?? []) !== [])
                    <div class="va-lbl">PHÂN ĐOẠN KHÔNG CẦN ẢNH RIÊNG</div>
                    @foreach($plan['unplated'] as $entry)
                        <div class="va-lbl" style="font-weight:400">· {{ $entry['beat'] }}{{ $entry['tour_stop'] !== null ? ' #'.$entry['tour_stop'] : '' }}{{ $entry['space'] !== null ? ' · '.$entry['space']['kind'].' '.$spaceLabel($entry['space']) : '' }} {{ $entry['part'] }}: {{ $entry['reason'] }} · bàn giao: {{ $entry['handoff'] }}</div>
                    @endforeach
                @endif
                @if(($plan['continuity'] ?? []) !== [] || ($plan['script_continuity'] ?? []) !== [])
                    <div class="va-lbl">NỐI CẢNH</div>
                    @foreach($plan['script_continuity'] ?? [] as $match)
                        <div class="va-lbl" style="font-weight:400">· kịch bản: điểm dừng #{{ $match['index'] }} {{ $match['room'] }} giữ góc máy của {{ $match['beat'] }} ({{ implode(', ', [...$match['bare'], ...$match['stop']]) }}): "{{ $match['requirement'] }}"</div>
                    @endforeach
                    @foreach($plan['continuity'] ?? [] as $entry)
                        <div class="va-lbl" style="font-weight:400">· {{ implode(', ', $entry['backgrounds']) }} ({{ implode(', ', $entry['beats']) }}): {{ $entry['requirement'] }}</div>
                    @endforeach
                @endif
                @if($shown['images_error'] !== null)
                    <div class="va-lbl" style="font-weight:400;color:var(--vp-red)">{{ $shown['images_error'] }}</div>
                @endif

                @if($gridRows->isNotEmpty())
                    <div class="va-lbl" id="locationsGridHead" data-ajax-part>
                        YÊU CẦU ẢNH BỐI CẢNH — {{ $gridRows->count() }} ẢNH
                        @if($shown['stale'] !== [])<span class="va-tag dg">PROMPT ĐÃ ĐỔI: {{ implode(', ', $shown['stale']) }}</span>@endif
                    </div>

                    @if($media['error'])
                        <div class="alert alert-danger">{{ $media['error'] }}</div>
                    @endif

                    <div class="ve-grid">
                        <div class="vs-h"><b>1</b> Clean plate prompt</div>
                        <div class="vs-h"><b>2</b> Render settings</div>
                        <div class="vs-h"><b>3</b> Rendered plates</div>

                        @foreach($gridRows as $row)
                            @php
                                $request = $row['request'];
                                $key = $request['id'];
                                $form = 'locForm_'.$key;
                                $modal = 'confirmLoc_'.$key;
                                $promptId = 'prompt_'.$key;
                                $stale = in_array($key, $shown['stale'], true);
                                $source = $request['source'];
                                $cards = collect($row['cells'])->flatMap(fn ($cell) => collect($cell['candidates'])->map(fn ($candidate) => ['cell' => $cell, 'candidate' => $candidate]))->values();
                                $pending = collect($row['cells'])->where('candidates', []);
                            @endphp

                            <div class="vs-row" id="ref_{{ $key }}">
                                <div class="vs-c">
                                    <span class="ve-name">{{ $key }} · {{ $request['name'] }}</span>
                                    <span class="ve-key">{{ $kinds[$request['kind']] }} {{ $source['id'] }}{{ $request['state'] !== null ? ' · '.$request['state'] : '' }}</span>
                                    <span id="locTags_{{ $key }}" data-ajax-part>@if($row['chosen'] !== null && $row['problem'] === null)<span class="va-tag ok" style="margin-left:6px">ĐÃ DUYỆT</span>@endif
                                    @if($row['problem'] !== null && ($row['chosen'] !== null || $stale || $request['blocked']))<span class="va-tag dg">{{ $row['problem'] }}</span>@endif</span>
                                    <div class="ve-key"><b>Nguồn:</b> {{ $sourceTypes[$source['type']] }} {{ $source['id'] }}{{ $source['reason'] !== null ? ' — '.$source['reason'] : '' }}@foreach($source['evidence'] as $evidence) · {{ $quoteOf($evidence) }}@endforeach</div>
                                    <div class="ve-key"><b>Không gian · trạng thái:</b> {{ $request['location']['name'] ?? $request['name'] }} · {{ $request['kind'] }} {{ $source['id'] }}{{ $request['state'] !== null ? ' · '.$request['state'] : '' }}{{ $request['condition'] !== [] ? ' · tình trạng: '.implode('; ', array_column($request['condition'], 'text')) : '' }}</div>
                                    <div class="ve-key"><b>Beat · phần:</b> {{ collect($request['parts'])->map(fn ($part) => $part['beat'].($part['tour_stop'] === null ? '' : ' #'.$part['tour_stop']).' '.$part['part'])->implode(' · ') }}</div>
                                    <div class="ve-key"><b>Dùng chung / tách:</b> {{ $request['sharing'] }}</div>
                                    <div class="ve-key"><b>Ánh sáng nền:</b> {{ $request['light']['description'] }} ({{ $quoteOf($request['light']['evidence']) }})</div>
                                    @if($request['shot_changes'] !== [])
                                        <div class="ve-key"><b>Đổi ở shot:</b> {{ collect($request['shot_changes'])->map(fn ($change) => implode(', ', $change['beats']).' '.$change['kind'].': '.$change['change'].' — giữ: '.$change['keep'])->implode(' · ') }}</div>
                                    @endif
                                    @if($request['must_show'] !== [])
                                        <div class="ve-key"><b>Chi tiết bắt buộc:</b> {{ implode(' · ', $request['must_show']) }}</div>
                                    @endif
                                    @if($request['assumptions'] !== [])
                                        <div class="ve-key"><b>Giả định:</b> {{ collect($request['assumptions'])->map(fn ($assumption) => $assumption['subject'].': '.$assumption['value'].' — '.$assumption['reason'])->implode(' · ') }}</div>
                                    @endif
                                    @if($request['conflicts'] !== [])
                                        <div class="ve-key" style="color:var(--vp-red)"><b>Xung đột:</b> {{ collect($request['conflicts'])->map(fn ($conflict) => $conflict['field'].': thiết kế "'.$conflict['design'].'" / kịch bản "'.$conflict['script'].'"')->implode(' · ') }}</div>
                                    @endif
                                    @if($request['continuity'] !== [] || $request['script_continuity'] !== [])
                                        <div class="ve-key"><b>Nối cảnh:</b> {{ collect($request['script_continuity'])->map(fn ($match) => 'kịch bản: điểm dừng #'.$match['index'].' giữ góc máy của '.$match['beat'].' — "'.$match['requirement'].'"')->merge(collect($request['continuity'])->map(fn ($entry) => $entry['requirement'].' ('.implode(', ', $entry['beats']).')'))->implode(' · ') }}</div>
                                    @endif
                                    <textarea class="va-ta" style="margin-top:8px" id="{{ $promptId }}" readonly>{{ $row['prompt'] }}</textarea>
                                    <div class="va-count">{{ mb_strlen($row['prompt']) }} ký tự</div>
                                    <div class="ve-key"><b>Nguồn prompt:</b> {{ implode(' · ', array_values(array_unique(array_column($request['prompt_parts'], 'source')))) }}</div>
                                    <div id="locStale_{{ $key }}" data-ajax-part>
                                        @if($stale)
                                            <div class="ve-key" style="color:var(--vp-red)">Prompt đã đổi so với lần render trước — Render Plate sẽ gửi prompt trên.</div>
                                        @endif
                                    </div>
                                </div>

                                <div class="vs-c">
                                    @if($media['error'] || $media['default'] === null)
                                        <div class="ve-locked" style="color:var(--vp-red)">Render đang khoá — danh sách model lỗi cấu hình.</div>
                                    @elseif(! $renderable)
                                        <div class="ve-locked">Bộ này chưa đạt RULE — không render.</div>
                                    @elseif($request['blocked'])
                                        <div class="ve-locked" style="color:var(--vp-red)">Bối cảnh còn xung đột kịch bản / thiết kế — không render.</div>
                                    @else
                                        <form method="POST" action="{{ route('video-projects.locations-render', $id) }}" id="{{ $form }}" data-modal="{{ $modal }}" data-ajax-part onsubmit="return vpLockForm(this)" hidden>
                                            @csrf
                                            <input type="hidden" name="request_id" value="{{ $key }}">
                                            <input type="hidden" name="stage_id" value="{{ $shown['id'] }}">
                                            <input type="hidden" name="content_hash" value="{{ $shown['content_hash'] }}">
                                            <input type="hidden" name="prompts_hash" value="{{ $shown['images_hash'] }}">
                                            <input type="hidden" name="prompt_sha256" value="{{ $row['sha256'] }}">
                                            <input type="hidden" name="selection_version" value="{{ $step['selection']['version'] }}">
                                            <input type="hidden" name="operation_id" value="{{ \Illuminate\Support\Str::uuid() }}">
                                        </form>
                                        @php
                                            $pinnedNote = $step['pinned'] === null ? ' Lần render đầu tiên dùng bộ rev '.$shown['revision'].' làm bộ bối cảnh.' : ($step['pinned']['id'] !== $shown['id'] ? ' Bộ đang dùng là rev '.$step['pinned']['revision'].'; render ở đây chuyển sang dùng rev '.$shown['revision'].', ảnh đã duyệt của rev '.$step['pinned']['revision'].' không dùng nữa.' : '');
                                        @endphp
                                        <div class="ve-set">
                                            <div class="va-field">
                                                <label>Model</label>
                                                <select class="ctl" name="provider_model" form="{{ $form }}" data-row="{{ $key }}" data-role="model" data-note="{{ $pinnedNote }}" required>
                                                    @foreach($media['models'] as $entry)
                                                        <option value="{{ $entry['id'] }}" data-pricing="{{ $entry['pricing'] }}"
                                                                @selected($entry['id'] === $media['default']['id'])>{{ $entry['label'] }}{{ $entry['pricing'] === 'unpriced' ? ' · chưa định giá' : '' }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            @foreach($media['models'] as $entry)
                                                @php
                                                    $active = $entry['id'] === $media['default']['id'];
                                                    $controls = $entry['controls'];
                                                @endphp
                                                <div class="ve-set" data-row="{{ $key }}" data-group="{{ $entry['id'] }}"
                                                     data-provider="{{ $entry['provider'] }}" data-label="{{ $entry['label'] }}"
                                                     data-default-size="{{ $controls['default_size'] ?? '' }}"
                                                     data-default-quality="{{ $controls['default_quality'] ?? '' }}"
                                                     @unless($active) hidden @endunless>
                                                    @if($entry['provider'] === 'openai')
                                                        <div class="va-field">
                                                            <label>Quality</label>
                                                            <select class="ctl" name="quality" form="{{ $form }}" required @disabled(! $active)>
                                                                @foreach($controls['qualities'] as $quality)
                                                                    <option value="{{ $quality }}" title="{{ \App\Enums\ImageQuality::tryFrom($quality)?->hint() }}"
                                                                            @selected($quality === $controls['default_quality'])>{{ \App\Enums\ImageQuality::tryFrom($quality)?->label() ?? $quality }}</option>
                                                                @endforeach
                                                            </select>
                                                        </div>
                                                        <div class="va-field">
                                                            <label>Size</label>
                                                            <select class="ctl" name="size" form="{{ $form }}" required @disabled(! $active)>
                                                                @foreach($controls['sizes'] as $size)
                                                                    <option value="{{ $size }}"
                                                                            @selected($size === $controls['default_size'])>{{ \App\Enums\ImageSize::tryFrom($size)?->label() ?? $size }}</option>
                                                                @endforeach
                                                            </select>
                                                        </div>
                                                    @else
                                                        <div class="va-field">
                                                            <label>Quality</label>
                                                            <div class="ve-locked">không áp dụng</div>
                                                        </div>
                                                        <div class="va-field">
                                                            <label>Aspect ratio</label>
                                                            <select class="ctl" name="aspect_ratio" form="{{ $form }}" required @disabled(! $active)>
                                                                @foreach($controls['aspect_ratios'] as $ratio)
                                                                    <option value="{{ $ratio }}"
                                                                            @selected($ratio === $controls['default_aspect_ratio'])>{{ $ratio }}{{ $ratio === $controls['default_aspect_ratio'] ? ' · khớp keyframe' : '' }}{{ in_array($ratio, $controls['proven_aspect_ratios'], true) ? '' : ' · chưa render thử' }}</option>
                                                                @endforeach
                                                            </select>
                                                        </div>
                                                        <div class="va-field">
                                                            <label>Image size</label>
                                                            <select class="ctl" name="image_size" form="{{ $form }}" required @disabled(! $active)>
                                                                @foreach($controls['image_sizes'] as $imageSize)
                                                                    <option value="{{ $imageSize }}"
                                                                            @isset($controls['prices'][$imageSize]) data-usd="{{ $controls['prices'][$imageSize] }}" @endisset
                                                                            @selected($imageSize === $controls['default_image_size'])>{{ $imageSize }}{{ in_array($imageSize, $controls['proven_image_sizes'], true) ? '' : ' · chưa render thử' }}</option>
                                                                @endforeach
                                                            </select>
                                                        </div>
                                                    @endif
                                                    <div class="va-field">
                                                        <label>Variations</label>
                                                        <select class="ctl" name="variations" form="{{ $form }}" required @disabled(! $active)>
                                                            @for($count = 1; $count <= $entry['max_variations']; $count++)
                                                                <option value="{{ $count }}" @selected($count === 1)>{{ $count }}</option>
                                                            @endfor
                                                        </select>
                                                    </div>
                                                    @unless(in_array($entry['id'], $media['rendered'], true))
                                                        <div class="ve-locked ve-unproven" style="color:var(--vp-amber-fg)">
                                                            Chưa có lần render Locations thật nào bằng model này.
                                                        </div>
                                                    @endunless
                                                </div>
                                            @endforeach
                                        </div>
                                        <div class="va-foot" style="padding-left:0;padding-right:0">
                                            <button type="button" class="vp-btn pri" data-toggle="modal" data-target="#{{ $modal }}" data-busy="Đang render…">Render Plate</button>
                                        </div>
                                        <div id="locModal_{{ $key }}" data-ajax-part>
                                            @include('modal.confirm_action', [
                                                'id' => $modal,
                                                'form' => $form,
                                                'content' => 'Render bối cảnh '.$key.' ('.$request['kind'].' '.$source['id'].($request['state'] !== null ? ' · '.$request['state'] : '').') — TÁC VỤ NÀY TÍNH TIỀN.',
                                                'detail' => 'Ước lượng theo chất lượng mặc định: $'.number_format((float) ($media['costs'][$media['default']['controls']['default_quality']] ?? 0), 3).' mỗi ảnh; khổ lớn hơn sẽ đắt hơn.'.$pinnedNote,
                                                'detailId' => 'est_'.$key,
                                            ])
                                        </div>
                                    @endif
                                </div>

                                <div class="vs-c" id="plates_{{ $key }}" data-ajax-part>
                                    @if($cards->isEmpty() && $pending->isEmpty())
                                        <div class="ve-empty">Chưa có ảnh nào cho bối cảnh này.</div>
                                    @endif
                                    @if($cards->isNotEmpty())
                                        <div class="va-cands">
                                            @foreach($cards as $card)
                                                @php
                                                    $cell = $card['cell'];
                                                    $candidate = $card['candidate'];
                                                    $inUse = ($row['chosen']['artifact_id'] ?? null) === $candidate['id'];
                                                @endphp
                                                <div class="va-cand">
                                                    <img src="{{ $candidate['url'] }}" alt="{{ $cell['image_code'] }}" width="{{ $candidate['width'] }}" height="{{ $candidate['height'] }}">
                                                    <div class="cap">
                                                        <span>{{ $key }}</span>
                                                        <span class="act">
                                                            <b class="{{ $cell['status_tone'] }}">{{ $cell['status_label'] }}</b>
                                                            @if($inUse)
                                                                <b class="ok">Đang dùng</b>
                                                            @elseif($cell['can_approve'] && $pinnedShown && ! $request['blocked'])
                                                                <form method="POST" action="{{ route('video-projects.locations-choose', $id) }}" onsubmit="return vpLockForm(this)">
                                                                    @csrf
                                                                    <input type="hidden" name="request_id" value="{{ $key }}">
                                                                    <input type="hidden" name="artifact_id" value="{{ $candidate['id'] }}">
                                                                    <input type="hidden" name="selection_version" value="{{ $step['selection']['version'] }}">
                                                                    <button class="vp-btn ok sm">✓ Duyệt</button>
                                                                </form>
                                                            @endif
                                                        </span>
                                                    </div>
                                                    <div class="meta">
                                                        <span>{{ $cell['image_code'] }}</span>
                                                        <span>{{ $candidate['width'] }}×{{ $candidate['height'] }}</span>
                                                        <span>{{ $cell['render_model'] }} &middot; {{ $cell['quality'] !== '' ? $cell['quality'] : 'không áp dụng' }}
                                                            @if($cell['cost_recorded_has_ledger']) &middot; @include('video-projects.partials.cost-recorded', ['cell' => $cell]) @endif</span>
                                                        <span>Created: {{ $candidate['created_at']?->format('Y-m-d H:i:s') ?? '—' }}</span>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif

                                    @foreach($pending as $cell)
                                        <div class="va-cell" style="padding-left:0;padding-right:0">
                                            <span class="code">{{ $cell['image_code'] }}</span>
                                            <span class="va-tag {{ $cell['status_tone'] }}">{{ $cell['status_label'] }}</span>
                                        </div>
                                        @if($cell['render_error'])
                                            <div class="alert alert-danger">{{ $cell['render_error'] }}</div>
                                        @endif
                                        @if($cell['can_render'] && $row['can_render'])
                                            <form method="POST" action="{{ route('video-projects.locations-render', $id) }}" id="retryLoc_{{ $cell['id'] }}" data-modal="confirmRetryLoc_{{ $cell['id'] }}" onsubmit="return vpLockForm(this)">
                                                @csrf
                                                <input type="hidden" name="request_id" value="{{ $key }}">
                                                <input type="hidden" name="image_id" value="{{ $cell['id'] }}">
                                                <input type="hidden" name="operation_id" value="{{ \Illuminate\Support\Str::uuid() }}">
                                            </form>
                                            <button type="button" class="vp-btn pri" data-toggle="modal" data-target="#confirmRetryLoc_{{ $cell['id'] }}" data-busy="Đang render…">{{ $cell['has_failed'] ? 'Render lại →' : 'Render →' }}</button>
                                            @include('modal.confirm_action', [
                                                'id' => 'confirmRetryLoc_'.$cell['id'],
                                                'form' => 'retryLoc_'.$cell['id'],
                                                'content' => 'Render lại ô '.$cell['image_code'].' với đúng thiết lập cũ — TÁC VỤ NÀY TÍNH TIỀN.',
                                                'detail' => $cell['variations'].' ảnh · '.$cell['quality'].' · '.$cell['size'].' — '.($cell['cost_estimate'] === null ? 'chưa định giá' : 'ước lượng $'.number_format($cell['cost_estimate'], 3)),
                                            ])
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                @if($check !== null && $shown['legacy'] === null && ($check['verdict'] !== 'PASS' || ! in_array($check['status'], ['ok', 'partial'], true)))
                    <div class="va-lbl" style="font-weight:400;color:var(--vp-dim)">Bộ này chỉ để xem: chỉ bộ đạt RULE mới render được.</div>
                @endif
            @endif
        </div>
    </div>
</div>
@endsection

@section('script')
<script src="{{ asset('assets/js/video-producer.js') }}?v={{ filemtime(public_path('assets/js/video-producer.js')) }}"></script>
<script>
(function () {
    var costs = @json($step['media']['costs'] ?? []);

    function init() {
        document.querySelectorAll('[data-role="model"]').forEach(function (picker) {
            var row = picker.getAttribute('data-row');
            var groups = document.querySelectorAll('[data-row="' + row + '"][data-group]');

            function field(group, name) {
                return group.querySelector('[name="' + name + '"]');
            }

            function activeGroup() {
                var found = null;
                Array.prototype.forEach.call(groups, function (group) {
                    var on = group.getAttribute('data-group') === picker.value;
                    group.hidden = !on;
                    Array.prototype.forEach.call(group.querySelectorAll('select'), function (el) { el.disabled = !on; });
                    if (on) { found = group; }
                });
                return found;
            }

            function sync() {
                var group = activeGroup();
                var box = document.getElementById('est_' + row);
                if (!box) { return; }
                if (!group) { box.textContent = 'Model không có thiết lập.'; return; }

                var note = picker.getAttribute('data-note') || '';
                var count = parseInt(field(group, 'variations').value, 10) || 1;
                var label = group.getAttribute('data-label');

                if (group.getAttribute('data-provider') !== 'openai') {
                    var sizeField = field(group, 'image_size');
                    var usd = sizeField.options[sizeField.selectedIndex].getAttribute('data-usd');

                    box.textContent = label + ' · ' + field(group, 'aspect_ratio').value + ' · '
                        + sizeField.value + ' · ' + count + ' ảnh — '
                        + (usd === null ? 'chưa định giá' : 'ước tính $' + (parseFloat(usd) * count).toFixed(3)) + note;
                    box.classList.remove('text-danger');
                    box.classList.add('text-muted');
                    return;
                }

                var quality = field(group, 'quality').value;
                var size = field(group, 'size').value;
                var unit = costs[quality];
                var off = [];
                if (quality !== group.getAttribute('data-default-quality')) { off.push('chất lượng'); }
                if (size !== group.getAttribute('data-default-size')) { off.push('khổ'); }

                var text = label + ' · ' + size + ' · ' + quality + ' · ' + count + ' ảnh — ước lượng '
                    + (unit === undefined ? 'không rõ' : '$' + (unit * count).toFixed(3));

                if (off.length) {
                    text += ' ⚠ Lệch mặc định: ' + off.join(' và ')
                        + '. Con số trên chỉ tính theo chất lượng — khổ lớn hơn sẽ đắt hơn.';
                }

                box.textContent = text + note;
                box.classList.toggle('text-danger', off.length > 0);
                box.classList.toggle('text-muted', off.length === 0);
            }

            picker.addEventListener('change', sync);
            Array.prototype.forEach.call(groups, function (group) {
                Array.prototype.forEach.call(group.querySelectorAll('select'), function (el) {
                    el.addEventListener('change', sync);
                });
            });
            sync();
        });
    }

    init();
    vpAjaxPage('locationsPage', init);
})();
</script>
@endsection
