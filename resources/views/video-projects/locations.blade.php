@extends('layouts.base', ['title' => 'Locations & Sets'])
@section('title', 'Locations & Sets')

@section('css')
<link rel="stylesheet" href="{{ asset('assets/css/video-producer.css') }}?v={{ filemtime(public_path('assets/css/video-producer.css')) }}">
@endsection

@section('content')
<div class="container-fluid vp">

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
        $doc = $shown['document']['data'] ?? null;
        $check = $shown['check'] ?? null;
        $refs = collect($step['references']);
        $required = $refs->where('request.required', true);
        $readyCount = $required->where('problem', null)->count();
        $media = $step['media'];
        $spaceLabel = static fn (array $space): string => $space['id'].($space['state'] !== null ? ' · '.$space['state'] : '');
    @endphp

    <div class="va-page">
        <div>
            <h1>Locations & Sets</h1>
            <p>Phase 5 — địa điểm ngoài tàu, phòng nội thất theo trạng thái thi công và khu ngoài trời trên tàu, lập từ kịch bản đã duyệt</p>
            <p style="margin:0;color:var(--vp-dim)">
                Nguồn: kịch bản đã duyệt và bản thiết kế mà kịch bản đã ghim
                @if($step['prompt'] !== null) &middot; {{ $step['prompt'] }} &middot; {{ $step['model'] }} @endif
            </p>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            @if($step['text_error'] === null && $refs->isNotEmpty())
                <span class="va-tag {{ $readyCount === $required->count() ? 'ok' : 'mute' }}">{{ $readyCount }} / {{ $required->count() }} ẢNH BẮT BUỘC ĐÃ CHỌN</span>
            @endif
            @if($step['set_approved'])<span class="va-tag ok">BỘ ẢNH ĐÃ DUYỆT</span>@endif
            <a class="vp-btn" href="{{ route('video-projects.anchor', $id) }}#scriptPanel">← Kịch bản</a>
        </div>
    </div>

    <div class="vp-panel" id="locationsText">
        <div class="va-head">
            <span class="n">1</span>
            <b>MÔ TẢ BỐI CẢNH</b>
            <em>LOC · INT · EXT, ánh sáng, bindings và danh sách ảnh tham chiếu</em>
            <span class="grow"></span>
            @if($step['running'])
                <button class="vp-btn sm" disabled>Đang lập…</button>
            @elseif($step['stuck'])
                <form method="POST" action="{{ route('video-projects.locations-reset', $id) }}">
                    @csrf
                    <button class="vp-btn sm dg">Reset lượt bị kẹt</button>
                </form>
            @elseif($step['can_run'] || $step['can_rewrite'])
                <form method="POST" action="{{ route('video-projects.locations-write', $id) }}" id="locationsWriteForm" data-modal="confirmLocationsWrite" onsubmit="return vpLockForm(this)">
                    @csrf
                    <input type="hidden" name="operation_id" value="{{ $step['operation_id'] }}">
                    @if($step['can_rewrite'])<input type="hidden" name="rewrite" value="1">@endif
                </form>
                <button type="button" class="vp-btn sm pri" data-toggle="modal" data-target="#confirmLocationsWrite" data-busy="Đang lập…">{{ $step['can_rewrite'] ? 'Lập lại (tính phí)' : 'Lập bối cảnh' }}</button>
                @include('modal.confirm_action', [
                    'id' => 'confirmLocationsWrite',
                    'form' => 'locationsWriteForm',
                    'content' => 'Gọi '.$step['model'].' lập địa điểm, bộ cảnh nội thất và danh sách ảnh tham chiếu từ kịch bản đã duyệt — tác vụ này tính tiền.',
                    'detail' => 'Tối đa '.number_format((int) $step['max_tokens'], 0, ',', '.').' token đầu ra, chờ tối đa '.$step['timeout_minutes'].' phút; trả về dạng stream, tiến độ xem được khi tải lại trang ở tab khác.',
                ])
            @else
                <button class="vp-btn sm" disabled title="{{ $step['run_error'] }}">Lập bối cảnh</button>
            @endif
        </div>

        <div class="va-body">
            <div class="va-brief">
                @if($step['run_error'] !== null)
                    <div class="line"><span class="k">Chưa lập được</span><span class="v" style="color:var(--vp-red)">{{ $step['run_error'] }}</span></div>
                @endif
                @include('video-projects.partials.stream-state', ['stream' => $step['stream']])
                @if($step['error'] !== null)
                    <div class="line"><span class="k">Lượt gần nhất lỗi</span><span class="v" style="color:var(--vp-red)">{{ $step['error'] }}</span></div>
                @endif
                @if($step['text_error'] !== null)
                    <div class="line"><span class="k">Bản mô tả đã duyệt</span><span class="v" style="color:var(--vp-red)">{{ $step['text_error'] }}</span></div>
                @endif
                @if($step['history'] !== [])
                    <div class="line"><span class="k">Các lượt</span><span class="v">
                        @foreach($step['history'] as $row)
                            <a href="{{ route('video-projects.locations', ['id' => $id, 'stage' => $row['id']]) }}">rev {{ $row['revision'] }}</a>
                            {{ $row['status'] }}{{ $row['verdict'] ? ' · '.$row['verdict'] : '' }}{{ $row['text_approved'] ? ' · đã duyệt' : '' }}@if(! $loop->last) &middot; @endif
                        @endforeach
                    </span></div>
                @endif
            </div>

            @if($shown !== null)
                <div class="va-lbl">
                    BẢN REV {{ $shown['revision'] }}
                    @if($shown['text_approved'])<span class="va-tag ok">ĐÃ DUYỆT MÔ TẢ</span>@endif
                    @if($check !== null)<span class="va-tag {{ $check['verdict'] === 'PASS' ? 'ok' : 'dg' }}">RULE: {{ $check['verdict'] }}</span><span class="va-tag mute">VISION: not_run</span>@endif
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

                @if(is_array($doc))
                    <div class="va-lbl">ĐỊA ĐIỂM</div>
                    <div class="vs-script-scroll">
                        <table class="vr-needs">
                            <thead><tr><th>ID</th><th>Loại</th><th>Không gian</th><th>Kích thước (m)</th><th>Ánh sáng</th><th>Anchor</th></tr></thead>
                            <tbody>
                                @foreach($doc['locations'] as $location)
                                    <tr>
                                        <td><b>{{ $location['id'] }}</b><br><em>{{ $location['name'] }}</em></td>
                                        <td>{{ $location['kind'] }}</td>
                                        <td>{{ $location['space'] }}</td>
                                        <td>@foreach(['length_m' => 'dài', 'width_m' => 'rộng', 'height_m' => 'cao'] as $axis => $word){{ $word }} {{ $location['dimensions'][$axis]['value'] ?? '—' }} <em>({{ $location['dimensions'][$axis]['basis'] }})</em><br>@endforeach</td>
                                        <td>@foreach($location['lighting'] as $light)<b>{{ $light['id'] }}</b> {{ $light['time'] }} · {{ $light['key']['source'] }} {{ $light['key']['kelvin'] }}K<br>@endforeach</td>
                                        <td>{{ $location['anchor'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="va-lbl">PHÒNG NỘI THẤT</div>
                    <div class="vs-script-scroll">
                        <table class="vr-needs">
                            <thead><tr><th>Phòng</th><th>Nhận dạng</th><th>Trạng thái · anchor</th><th>Ánh sáng</th></tr></thead>
                            <tbody>
                                @foreach($doc['interior_sets'] as $set)
                                    <tr>
                                        <td><b>{{ $set['room'] }}</b></td>
                                        <td>{{ $set['identity']['geometry'] }}<br><em>{{ $set['identity']['window'] }} · {{ $set['identity']['structure'] }}</em></td>
                                        <td>@foreach($set['states'] as $state)<b>{{ $state['state'] }}</b>: {{ $state['anchor'] }}<br>@endforeach</td>
                                        <td>@foreach($set['lighting'] as $light)<b>{{ $light['id'] }}</b> {{ $light['time'] }} · {{ $light['key']['kelvin'] }}K<br>@endforeach</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if($doc['exterior_amenities'] !== [])
                        <div class="va-lbl">KHU NGOÀI TRỜI TRÊN TÀU</div>
                        <div class="vs-script-scroll">
                            <table class="vr-needs">
                                <thead><tr><th>Vùng</th><th>Đặt trong</th><th>Vận hành</th><th>Anchor</th></tr></thead>
                                <tbody>
                                    @foreach($doc['exterior_amenities'] as $amenity)
                                        <tr><td><b>{{ $amenity['zone'] }}</b></td><td>{{ $amenity['setting'] ?? '—' }}</td><td>{{ $amenity['operating'] ?? '—' }}</td><td>{{ $amenity['anchor'] }}</td></tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    <div class="va-lbl">BINDINGS — BEAT VÀ TOUR</div>
                    <div class="vs-script-scroll">
                        <table class="vr-needs">
                            <thead><tr><th>Beat</th><th>Tour</th><th>Phần</th><th>Không gian</th><th>Ánh sáng</th></tr></thead>
                            <tbody>
                                @foreach($doc['bindings'] as $binding)
                                    <tr><td><b>{{ $binding['beat'] }}</b></td><td>{{ $binding['tour_stop'] === null ? '—' : '#'.$binding['tour_stop'] }}</td><td>{{ $binding['part'] }}</td><td>{{ $binding['space']['kind'] }} {{ $spaceLabel($binding['space']) }}</td><td>{{ $binding['lighting'] }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if($doc['production_assumptions'] !== [])
                        <div class="va-lbl">GIẢ ĐỊNH SẢN XUẤT</div>
                        @foreach($doc['production_assumptions'] as $assumption)
                            <div class="va-lbl" style="font-weight:400">· {{ $assumption['subject'] }}: {{ $assumption['value'] }} — {{ $assumption['reason'] }}</div>
                        @endforeach
                    @endif
                @endif

                @if($shown['approvable'])
                    @php
                        $planned = collect($shown['plan']['requests'] ?? []);
                    @endphp
                    <form method="POST" action="{{ route('video-projects.locations-approve', $id) }}" onsubmit="return vpLockForm(this)">
                        @csrf
                        <input type="hidden" name="stage_id" value="{{ $shown['id'] }}">
                        <input type="hidden" name="content_hash" value="{{ $shown['content_hash'] }}">
                        <input type="hidden" name="selection_version" value="{{ $step['selection']['version'] }}">
                        <input type="hidden" name="operation_id" value="{{ $step['operation_id'] }}">
                        <div class="va-foot">
                            <span class="va-lbl" style="font-weight:400">{{ $planned->where('required', true)->count() }} ảnh bắt buộc · {{ $planned->where('required', false)->count() }} ảnh hỗ trợ</span>
                            <button type="submit" class="vp-btn sm pri" data-busy="Đang duyệt…">Duyệt mô tả</button>
                        </div>
                    </form>
                @elseif($check !== null && $check['verdict'] !== 'PASS')
                    <div class="va-lbl" style="font-weight:400;color:var(--vp-dim)">Bản này chỉ để xem: chỉ bản status ok và đạt RULE mới duyệt được.</div>
                @endif
            @endif
        </div>
    </div>

    @if($refs->isNotEmpty())
        @if($media['error'])
            <div class="alert alert-danger">{{ $media['error'] }}</div>
        @endif

        <div class="ve-grid" style="margin-top:10px">
            <div class="vs-h"><b>2</b> Reference prompt</div>
            <div class="vs-h"><b>3</b> Render settings</div>
            <div class="vs-h"><b>4</b> Rendered references</div>

            @foreach($refs as $row)
                @php
                    $request = $row['request'];
                    $key = $request['id'];
                    $form = 'locForm_'.$key;
                    $modal = 'confirmLoc_'.$key;
                    $cards = collect($row['cells'])->flatMap(fn ($cell) => collect($cell['candidates'])->map(fn ($candidate) => ['cell' => $cell, 'candidate' => $candidate]))->values();
                    $pending = collect($row['cells'])->where('candidates', []);
                @endphp

                <div class="vs-row" id="ref_{{ $key }}">
                    <div class="vs-c">
                        <span class="ve-name">{{ $key }} · {{ $request['space']['kind'] }} {{ $spaceLabel($request['space']) }}</span>
                        <span class="ve-key">{{ $request['lighting'] }} · {{ $request['view'] }}{{ $request['uses'] !== [] ? ' · beat '.implode(', ', $request['uses']) : '' }}</span>
                        @if($request['inputs'] !== [])
                            <span class="ve-key">Ảnh nguồn: {{ collect($request['inputs'])->map(fn ($input) => ($input['kind'] === 'model_sheet' ? 'model sheet' : $input['id']).' ('.$input['role'].')')->implode(', ') }}</span>
                        @endif
                        <span class="va-tag {{ $request['required'] ? 'amber' : 'mute' }}" style="margin-left:6px">{{ $request['required'] ? 'BẮT BUỘC' : 'HỖ TRỢ' }}</span>
                        @if($row['chosen'] !== null && $row['problem'] === null)<span class="va-tag ok">ĐÃ CHỌN</span>@endif
                        @if($row['problem'] !== null)<span class="va-tag dg">{{ $row['problem'] }}</span>@endif
                        <div class="ve-key">{{ $request['purpose'] }}</div>
                        <textarea class="va-ta" style="margin-top:8px" readonly>{{ $row['prompt'] }}</textarea>
                        <div class="va-count">{{ mb_strlen($row['prompt']) }} ký tự · {{ \App\Video\Locations\LocationReferencePrompt::VERSION }}</div>
                    </div>

                    <div class="vs-c">
                        @if($media['error'] || $media['default'] === null)
                            <div class="ve-locked" style="color:var(--vp-red)">Render đang khoá — danh sách model lỗi cấu hình.</div>
                        @elseif(! $row['can_render'])
                            <div class="ve-locked" style="color:var(--vp-amber-fg)">Chọn ảnh cho {{ implode(', ', $row['waiting_for']) }} trước — yêu cầu này dựng từ ảnh đó.</div>
                        @else
                            <form method="POST" action="{{ route('video-projects.locations-render', $id) }}" id="{{ $form }}" data-modal="{{ $modal }}" onsubmit="return vpLockForm(this)" hidden>
                                @csrf
                                <input type="hidden" name="request_id" value="{{ $key }}">
                                <input type="hidden" name="operation_id" value="{{ \Illuminate\Support\Str::uuid() }}">
                            </form>
                            <div class="ve-set">
                                <div class="va-field">
                                    <label>Model</label>
                                    <select class="ctl" name="provider_model" form="{{ $form }}" data-row="{{ $key }}" data-role="model" required>
                                        @foreach($media['models'] as $entry)
                                            <option value="{{ $entry['id'] }}" @selected($entry['id'] === $media['default']['id'])>{{ $entry['label'] }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                @foreach($media['models'] as $entry)
                                    @php
                                        $active = $entry['id'] === $media['default']['id'];
                                        $controls = $entry['controls'];
                                    @endphp
                                    <div class="ve-set" data-row="{{ $key }}" data-group="{{ $entry['id'] }}" @unless($active) hidden @endunless>
                                        <div class="va-field">
                                            <label>Quality</label>
                                            <select class="ctl" name="quality" form="{{ $form }}" required @disabled(! $active)>
                                                @foreach($controls['qualities'] as $quality)
                                                    <option value="{{ $quality }}" @selected($quality === $controls['default_quality'])>{{ \App\Enums\ImageQuality::tryFrom($quality)?->label() ?? $quality }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="va-field">
                                            <label>Size</label>
                                            <select class="ctl" name="size" form="{{ $form }}" required @disabled(! $active)>
                                                @foreach($controls['sizes'] as $size)
                                                    <option value="{{ $size }}" @selected($size === $controls['default_size'])>{{ $size }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="va-field">
                                            <label>Variations</label>
                                            <select class="ctl" name="variations" form="{{ $form }}" required @disabled(! $active)>
                                                @for($count = 1; $count <= $entry['max_variations']; $count++)
                                                    <option value="{{ $count }}" @selected($count === 1)>{{ $count }}</option>
                                                @endfor
                                            </select>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                            <div class="va-foot" style="padding-left:0;padding-right:0">
                                <button type="button" class="vp-btn pri" data-toggle="modal" data-target="#{{ $modal }}" data-busy="Đang render…">{{ $row['cells'] === [] ? 'Render Reference' : 'Render biến thể mới' }}</button>
                            </div>
                            @include('modal.confirm_action', [
                                'id' => $modal,
                                'form' => $form,
                                'content' => 'Render ảnh tham chiếu '.$key.' ('.$request['space']['kind'].' '.$spaceLabel($request['space']).') — TÁC VỤ NÀY TÍNH TIỀN.',
                                'detail' => 'Ước lượng theo chất lượng mặc định: $'.number_format((float) ($media['costs'][$media['default']['controls']['default_quality']] ?? 0), 3).' mỗi ảnh; khổ lớn hơn sẽ đắt hơn.',
                            ])
                        @endif
                    </div>

                    <div class="vs-c">
                        @if($cards->isEmpty() && $pending->isEmpty())
                            <div class="ve-empty">Chưa có ảnh nào cho yêu cầu này.</div>
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
                                                @elseif($cell['can_approve'])
                                                    <form method="POST" action="{{ route('video-projects.locations-choose', $id) }}" onsubmit="return vpLockForm(this)">
                                                        @csrf
                                                        <input type="hidden" name="request_id" value="{{ $key }}">
                                                        <input type="hidden" name="artifact_id" value="{{ $candidate['id'] }}">
                                                        <input type="hidden" name="selection_version" value="{{ $step['selection']['version'] }}">
                                                        <button class="vp-btn ok sm">✓ Chọn</button>
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
                            @if($cell['can_render'])
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

        <div class="vp-panel" style="margin-top:10px" id="locationsSet">
            <div class="va-head">
                <span class="n">5</span>
                <b>DUYỆT BỘ ẢNH PHASE 5</b>
                <em>mọi ảnh bắt buộc đã chọn, file và hash còn đúng, ảnh nguồn không đổi</em>
                <span class="grow"></span>
                @if($step['set_approved'])
                    <span class="va-tag ok">ĐÃ DUYỆT · SẴN SÀNG CHO CÔNG ĐOẠN SAU</span>
                @else
                    <form method="POST" action="{{ route('video-projects.locations-approve-set', $id) }}" onsubmit="return vpLockForm(this)">
                        @csrf
                        <input type="hidden" name="selection_version" value="{{ $step['selection']['version'] }}">
                        <input type="hidden" name="operation_id" value="{{ $step['operation_id'] }}">
                        <button type="submit" class="vp-btn sm pri" data-busy="Đang duyệt…" @disabled($readyCount !== $required->count())>Duyệt bộ ảnh Phase 5</button>
                    </form>
                @endif
            </div>
            <div class="va-body">
                <div class="va-brief">
                    <div class="line"><span class="k">Kiểm ảnh</span><span class="v">vision: not_run &middot; duyệt tay khi bấm duyệt bộ</span></div>
                    @foreach($required->where('problem', '!==', null) as $row)
                        <div class="line"><span class="k">{{ $row['request']['id'] }}</span><span class="v" style="color:var(--vp-red)">{{ $row['problem'] }}</span></div>
                    @endforeach
                    @if($step['source_error'] !== null)
                        <div class="line"><span class="k">Nguồn đầu ra</span><span class="v" style="color:var(--vp-red)">{{ $step['source_error'] }}</span></div>
                    @endif
                    @if($step['source'] !== null)
                        <div class="line"><span class="k">Nguồn đầu ra</span><span class="v">{{ count($step['source']['manifest']) }} ảnh · manifest {{ substr($step['source']['token']['manifest_hash'], 0, 19) }} · {{ $step['source']['review']['vision'] }} / {{ $step['source']['review']['manual_review'] }}</span></div>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
@endsection

@section('script')
<script src="{{ asset('assets/js/video-producer.js') }}?v={{ filemtime(public_path('assets/js/video-producer.js')) }}"></script>
<script>
(function () {
    document.querySelectorAll('[data-role="model"]').forEach(function (picker) {
        var row = picker.getAttribute('data-row');
        var groups = document.querySelectorAll('[data-row="' + row + '"][data-group]');

        function sync() {
            Array.prototype.forEach.call(groups, function (group) {
                var on = group.getAttribute('data-group') === picker.value;
                group.hidden = !on;
                Array.prototype.forEach.call(group.querySelectorAll('select'), function (el) { el.disabled = !on; });
            });
        }

        picker.addEventListener('change', sync);
        sync();
    });
})();
</script>
@endsection
