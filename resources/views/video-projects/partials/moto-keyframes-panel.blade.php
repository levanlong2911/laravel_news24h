@php
    $panel = $motoKeyframes;
    $states = $panel['states'];
    $pixels = static fn (string $size): string => str_replace('x', ' × ', $size);
    $stateTag = static fn (string $state): string => '<span class="va-tag '.($states[$state][0] ?? 'mute').'">'.e($states[$state][1] ?? $state).'</span>';
    $fragment = $fragment ?? false;
    $framing = $panel['framing'] ?? null;
    $faultLabels = \App\Services\Video\MotoKeyframeService::FAULT_LABELS;
@endphp

@unless($fragment)
<div class="container-fluid vp" id="keyframesPage">
    @include('video-projects.partials.moto-reference-flash', ['flashId' => 'keyframesFlash'])

    <div class="vp-crumb">
        <a href="{{ route('video-projects.index') }}">Video Projects</a>
        <span class="sep">/</span>
        <a href="{{ route('video-projects.anchor', $id) }}">Kịch bản</a>
        <span class="sep">·</span>
        <a href="{{ route('video-projects.scene', $id) }}">Scenes</a>
        <span class="sep">·</span>
        <a href="{{ route('video-projects.reference', $id) }}">Reference</a>
        <span class="sep">·</span>
        <b>Keyframes</b>
        <span class="grow"></span>
        <span class="m">{{ count($panel['rows']) }} shot</span>
    </div>

    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="vp-panel va-anchor">
        <div class="va-head">
            <b>KEYFRAME</b>
            <em>khung đầu của từng shot — prompt ảnh do AI viết và người duyệt, gửi nguyên văn kèm ảnh tham chiếu nguyên tấm</em>
            <span class="grow"></span>
            @foreach($panel['counts'] as $state => $count)
                <span class="va-tag {{ $states[$state][0] ?? 'mute' }}">{{ $count }} {{ $states[$state][1] ?? $state }}</span>
            @endforeach
        </div>
        <div class="va-body">
            <div class="va-brief">
                @if($panel['error'] !== null)
                    <div class="line"><span class="k">Khoá render</span><span class="v" style="color:var(--vp-red)">{{ $panel['error'] }}</span></div>
                @else
                    <div class="line"><span class="k">Kế hoạch shot</span><span class="v">rev {{ $panel['stage']->planning_revision }} · {{ substr((string) $panel['stage']->id, 0, 8) }} · {{ $panel['token']['content_hash'] }}</span></div>
                    @if($framing !== null)
                        <div class="line"><span class="k">Khung hình</span><span class="v">ảnh gốc {{ $framing['master_aspect_ratio'] }} · ảnh video {{ $framing['delivery_aspect_ratio'] }} {{ $framing['delivery_width'] }} × {{ $framing['delivery_height'] }}, cắt từ ảnh gốc, không phóng lớn</span></div>
                    @endif
                @endif
            </div>
        </div>
    </div>
@endunless

    @foreach($panel['rows'] as $row)
        @php
            $shot = $row['shot'];
            $settings = $row['settings'];
            $renderForm = 'kfRender'.$shot['id'];
            $promptBox = 'kfPrompt'.$shot['id'];
            $promptForm = 'kfPromptWrite'.$shot['id'];
            $used = collect($row['candidates'])->firstWhere('revocable', true);
            $writer = $row['writer'];
            $shown = $writer['shown'];
            $promptText = $shown['prompt'] ?? ($row['prompt']['text'] ?? '');
            $editForm = $row['edit_form'];
        @endphp
        <div class="vs-shot-detail" id="shot-{{ $shot['id'] }}" data-ajax-part>
            <div class="vs-shot-grid">
                <div class="vs-h"><b>1</b> Planning</div>
                <div class="vs-h"><b>2</b> Prompt</div>
                <div class="vs-h"><b>3</b> References</div>
                <div class="vs-h"><b>4</b> Keyframes</div>

                <div class="vs-c vs-plan">
                    <span class="o">{{ $shot['id'] }}</span>
                    <span class="t">{{ $shot['purpose'] }}</span>
                    {!! $stateTag($row['state']) !!}
                    @foreach($row['planning'] as $group)
                        <details class="vk-group" @if($group['open']) open @endif>
                            <summary>{{ $group['title'] }}</summary>
                            @foreach($group['lines'] as $line)
                                <div class="d"><b>{{ $line['key'] }}</b>: {{ $line['text'] }}</div>
                            @endforeach
                        </details>
                    @endforeach
                </div>

                <div class="vs-c vs-prompt">
                    <div class="va-lbl">Prompt ảnh (keyframe) — AI viết, gửi nguyên văn bản đã duyệt</div>
                    @if($row['prompt'] !== null)
                        <div class="d">Đang dùng: lượt {{ substr($row['prompt']['stage_id'], 0, 8) }} · {{ $row['prompt']['version'] }}</div>
                    @endif
                    @if($shown !== null && ! $shown['selected'])
                        <div class="d">Chưa duyệt — bản dưới là lượt viết mới nhất ({{ substr($shown['id'], 0, 8) }})</div>
                    @endif
                    @if($writer['stale'])
                        <div class="d" style="color:var(--vp-amber-fg)">Nguồn đã đổi — cần viết hoặc duyệt lại</div>
                    @endif
                    <textarea class="va-ta" id="{{ $promptBox }}" readonly>{{ $promptText }}</textarea>
                    <div class="va-count"><span>{{ mb_strlen($promptText) }}</span> ký tự</div>
                    <button type="button" class="vp-btn sm" data-copy-prompt="{{ $promptBox }}">Sao chép prompt</button>
                    @if($shown !== null)
                        <div class="d">Lượt {{ $shown['revision'] }} · {{ $shown['verdict'] ?? '—' }} · {{ $shown['status'] ?? '—' }} · {{ $shown['model'] }} · {{ number_format($shown['tokens']['in']) }}/{{ number_format($shown['tokens']['out']) }} token @if($shown['cost_usd'] !== null) · ${{ number_format($shown['cost_usd'], 4) }}@endif</div>
                        @if($shown['error'] !== null)
                            <div class="d" style="color:var(--vp-red)">{{ $shown['error'] }}</div>
                        @endif
                        @foreach([...$shown['schema'], ...$shown['issues']] as $issue)
                            <div class="d" style="color:var(--vp-red)">{{ $issue }}</div>
                        @endforeach
                        @foreach((array) ($shown['requirements'] ?? []) as $requirement)
                            <div class="d" style="color:var(--vp-amber-fg)">Điều kiện để dùng ở bước sau: {{ $requirement }}</div>
                        @endforeach
                        @foreach((array) ($shown['notes'] ?? []) as $note)
                            <div class="d" style="color:var(--vp-dim)">Ghi chú nội dung (không chặn): {{ $note }}</div>
                        @endforeach
                        @foreach($shown['gaps'] as $gap)
                            <div class="d" style="color:var(--vp-amber-fg)">Thiếu dữ kiện: {{ $gap['missing'] }} — {{ $gap['why'] }}</div>
                        @endforeach
                        @foreach($shown['conflicts'] as $conflict)
                            <div class="d" style="color:var(--vp-amber-fg)">Mâu thuẫn ({{ implode(' / ', $conflict['sources']) }}): {{ $conflict['what'] }}</div>
                        @endforeach
                        @if(! $shown['current'])
                            <div class="d" style="color:var(--vp-amber-fg)">Lượt này viết từ nguồn cũ</div>
                        @endif
                        @if($shown['approvable'])
                            <form method="POST" action="{{ route('video-projects.keyframe-action', [$id, $shot['id'], 'prompt-approve']) }}" onsubmit="return vpLockForm(this)">
                                @csrf
                                <input type="hidden" name="stage_id" value="{{ $shown['id'] }}">
                                <button class="vp-btn sm ok" data-busy="Đang duyệt…">✓ Duyệt và dùng prompt này</button>
                            </form>
                        @endif
                    @endif
                    @if($writer['running'])
                        <div class="d"><span class="dot"></span>Đang viết prompt ảnh…</div>
                        <div class="va-brief">@include('video-projects.partials.stream-state', ['stream' => $writer['stream']])</div>
                    @elseif($writer['pending'])
                        <div class="d">Đang chờ máy chạy nền nhận lượt viết.</div>
                    @elseif($writer['error'] !== null)
                        <div class="d" style="color:var(--vp-red)">{{ $writer['error'] }}</div>
                    @endif
                    @if($writer['unavailable'] !== null)
                        <div class="d" style="color:var(--vp-amber-fg)">Chưa viết được: {{ $writer['unavailable'] }}</div>
                    @endif
                    <form method="POST" action="{{ route('video-projects.keyframe-action', [$id, $shot['id'], 'prompt']) }}" id="{{ $promptForm }}" data-modal="confirm{{ $promptForm }}" onsubmit="return vpLockForm(this)" hidden>
                        @csrf
                        <input type="hidden" name="operation_id" value="{{ $writer['operation_id'] }}">
                        <input type="hidden" name="confirm_cost" value="1">
                        <input type="hidden" name="regenerate" value="{{ $shown !== null ? 1 : 0 }}">
                    </form>
                    <div class="vk-actions">
                        <button type="button" class="vp-btn sm pri" data-toggle="modal" data-target="#confirm{{ $promptForm }}" data-busy="Đang gửi…"
                                @if(! $writer['can_write']) disabled title="{{ $writer['unavailable'] ?? 'Đang có lượt viết chờ hoặc đang chạy' }}" @endif>{{ $shown !== null ? 'Viết lại prompt ảnh' : 'Viết prompt ảnh bằng AI' }}</button>
                    </div>
                    @if($writer['estimate'] !== null)
                        @include('modal.confirm_action', ['id' => 'confirm'.$promptForm, 'form' => $promptForm, 'content' => 'Gọi '.$writer['estimate']['model'].' (effort '.$writer['estimate']['effort'].') một lần để viết prompt khung đầu của '.$shot['id'].' từ dữ liệu shot và '.$writer['estimate']['images'].' ảnh tham chiếu — TÁC VỤ NÀY TÍNH TIỀN. Viết xong không tự duyệt và không render.', 'detail' => 'Dự toán '.$writer['estimate']['text'].'.'])
                    @endif
                </div>

                <div class="vs-c vs-refs">
                    <div class="vs-slots">
                        @foreach($row['slots'] as $card)
                            @php
                                $sent = $card['source'] === null ? null : collect($row['sources'])->firstWhere('artifact_id', $card['source']['artifact_id']);
                            @endphp
                            <div class="vk-card">
                                @if($card['empty'])
                                    <div class="vs-slot">{{ $card['name'] }}</div>
                                    <div class="d">Trống — không gửi</div>
                                @elseif($card['source'] !== null)
                                    <div class="vs-slot filled {{ $card['problem'] === null && ! ($card['left_out'] ?? false) ? 'sent' : '' }}" title="{{ $card['source']['code'] }} · {{ $card['source']['sha256'] }}">
                                        <a href="{{ route('video-artifacts.show', $card['source']['artifact_id']) }}" target="_blank" rel="noopener"><img src="{{ route('video-artifacts.show', $card['source']['artifact_id']) }}" alt="{{ $card['source']['code'] }}" loading="lazy"></a>
                                        <span class="cap">
                                            @if($sent !== null)<b class="n">{{ $sent['position'] + 1 }}</b>@endif
                                            {{ $card['name'] }}
                                        </span>
                                    </div>
                                    <div class="d">{{ $card['source']['code'] }}</div>
                                @else
                                    <div class="vs-slot">{{ $card['name'] }}</div>
                                @endif
                                <div class="d">Vai trò: {{ $card['role'] }}</div>
                                @if($card['empty'] && isset($card['detail']))
                                    <div class="d">{{ $card['detail'] }}</div>
                                @endif
                                @if($card['problem'] !== null)
                                    <div class="d" style="color:var(--vp-amber-fg)">{{ $card['problem'] }}</div>
                                @elseif(! $card['empty'] && $card['kind'] !== 'environment')
                                    <div class="d">{{ $card['status'] }}</div>
                                @endif
                                @if(! $card['empty'] && $card['options'] === [])
                                    <button type="button" class="vp-btn sm" disabled title="Không có ảnh khác dùng được">Đổi ảnh</button>
                                @elseif($card['options'] !== [])
                                    <details class="vk-swap">
                                        <summary class="vp-btn sm">{{ $card['empty'] ? 'Chọn ảnh nền' : 'Đổi ảnh' }}</summary>
                                        <div class="vk-swap-list">
                                            @foreach($card['options'] as $option)
                                                <form method="POST" action="{{ route('video-projects.keyframe-action', [$id, $shot['id'], 'source']) }}" onsubmit="return vpLockForm(this)">
                                                    @csrf
                                                    <input type="hidden" name="kind" value="{{ $card['kind'] }}">
                                                    @if($card['source'] !== null)<input type="hidden" name="from" value="{{ $card['source']['artifact_id'] }}">@endif
                                                    <input type="hidden" name="to" value="{{ $option['artifact_id'] }}">
                                                    <button>
                                                        <img src="{{ route('video-artifacts.show', $option['artifact_id']) }}" alt="{{ $option['code'] }}" loading="lazy">
                                                        {{ $option['code'] }}
                                                    </button>
                                                </form>
                                            @endforeach
                                        </div>
                                    </details>
                                @endif
                                @if(! $card['empty'] && $card['kind'] === 'design' && $card['source'] !== null)
                                    @php
                                        $off = $card['left_out'] ?? false;
                                    @endphp
                                    @if($off)<div class="d" style="color:var(--vp-amber-fg)">Đang bỏ — {{ implode(', ', $card['parts']) }} không có nguồn chi tiết trong shot này</div>@endif
                                    <form method="POST" action="{{ route('video-projects.keyframe-action', [$id, $shot['id'], $off ? 'source-on' : 'source-off']) }}" onsubmit="return vpLockForm(this)">
                                        @csrf
                                        <input type="hidden" name="from" value="{{ $card['source']['artifact_id'] }}">
                                        <button class="vp-btn sm" data-busy="Đang lưu…">{{ $off ? 'Gửi lại master này' : 'Bỏ master này' }}</button>
                                    </form>
                                @endif
                                @if($card['reset'] !== null)
                                    <form method="POST" action="{{ route('video-projects.keyframe-action', [$id, $shot['id'], 'source-reset']) }}" onsubmit="return vpLockForm(this)">
                                        @csrf
                                        <input type="hidden" name="kind" value="{{ $card['kind'] }}">
                                        <button class="vp-btn sm" data-busy="Đang bỏ…">{{ $card['reset'] }}</button>
                                    </form>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    <div class="vk-controls">
                        <div class="va-field">
                            <label>Model</label>
                            <select class="ctl" name="model" form="{{ $renderForm }}" required data-keyframe-models="{{ json_encode(array_map(static fn (array $entry): array => ['model' => $entry['model'], 'qualities' => $entry['qualities'], 'sizes' => $entry['sizes'], 'default_quality' => $entry['default_quality'], 'default_size' => $entry['default_size']], $settings['models'])) }}">
                                @foreach($settings['models'] as $entry)
                                    <option value="{{ $entry['model'] }}" @selected($entry['model'] === $settings['model'])>{{ $entry['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="va-field">
                            <label>Quality</label>
                            <select class="ctl" name="quality" form="{{ $renderForm }}">
                                @forelse($settings['qualities'] as $quality)
                                    <option value="{{ $quality }}" @selected($quality === $settings['quality'])>{{ $quality }}</option>
                                @empty
                                    <option value="">—</option>
                                @endforelse
                            </select>
                        </div>
                        <div class="va-field">
                            <label>Size <em>({{ $panel['frame_ratio'] }})</em></label>
                            <select class="ctl" name="size" form="{{ $renderForm }}" required>
                                @foreach($settings['sizes'] as $size)
                                    <option value="{{ $size }}" @selected($size === $settings['size'])>{{ $pixels($size) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="va-field"><label>Số ảnh <em>(cố định)</em></label><select class="ctl" disabled><option>1</option></select></div>
                    </div>
                    <div class="d">
                        @if($row['price'] !== null)
                            Dự toán: ${{ number_format((float) $row['price']['usd'], 3) }} / ảnh (bảng giá {{ $row['price']['version'] }}, ước tính, {{ $settings['qualities'] === [] ? 'theo size' : 'chưa phản ánh size' }})
                        @else
                            Dự toán: chưa có giá cho model/chất lượng này
                        @endif
                    </div>
                    <div class="d">Master Reference là nguồn hình học; prompt shot quyết định trạng thái cần dựng.</div>
                    @foreach($row['problems'] as $problem)
                        <div class="d" style="color:var(--vp-amber-fg)">{{ $problem }}</div>
                    @endforeach

                    <form method="POST" action="{{ route('video-projects.keyframe-action', [$id, $shot['id'], 'render']) }}" id="{{ $renderForm }}" data-modal="confirm{{ $renderForm }}" onsubmit="return vpLockForm(this)" hidden>
                        @csrf
                        <input type="hidden" name="preview_hash" value="{{ $row['preview_hash'] }}">
                        <input type="hidden" name="operation_id" value="{{ \Illuminate\Support\Str::uuid() }}">
                        <input type="hidden" name="confirm_cost" value="1">
                    </form>
                    <form method="POST" action="{{ route('video-projects.keyframe-action', [$id, $shot['id'], 'settings']) }}" data-settings-of="{{ $renderForm }}" hidden>
                        @csrf
                        <input type="hidden" name="model" value="{{ $settings['model'] }}">
                        <input type="hidden" name="quality" value="{{ $settings['quality'] }}">
                        <input type="hidden" name="size" value="{{ $settings['size'] }}">
                    </form>
                    <div class="vk-actions">
                        <button type="button" class="vp-btn pri" data-toggle="modal" data-target="#confirm{{ $renderForm }}" data-busy="Đang gửi…"
                                @if(! $row['renderable']) disabled title="{{ $row['busy'] ? 'Shot đang có một lượt chờ hoặc đang render' : implode(' · ', $row['problems']) }}" @endif>{{ $row['has_candidates'] ? 'Tạo bản khác' : 'Render Image' }}</button>
                    </div>
                    @include('modal.confirm_action', ['id' => 'confirm'.$renderForm, 'form' => $renderForm, 'content' => 'Gửi prompt keyframe của '.$shot['id'].' kèm '.count($row['sources']).' ảnh nguyên tấm theo model, quality và size đang chọn: một request, một ảnh — TÁC VỤ NÀY TÍNH TIỀN.', 'detail' => 'Xem dự toán ở cột References.'])
                </div>

                <div class="vs-c vs-img">
                    @if($used === null)
                        <div class="frame">Chưa có keyframe đang dùng</div>
                    @endif
                    @foreach($row['candidates'] as $candidate)
                        @php
                            $image = $candidate['image'];
                            $artifact = $candidate['artifact'];
                            $spec = $candidate['spec'];
                        @endphp
                        <div class="vk-candidate">
                            @if($artifact !== null)
                                <a class="frame" href="{{ route('video-artifacts.show', $artifact->id) }}" target="_blank" rel="noopener">
                                    <img src="{{ route('video-artifacts.show', $artifact->id) }}" alt="{{ $image->image_code }}" width="{{ $artifact->width }}" height="{{ $artifact->height }}" loading="lazy">
                                </a>
                            @endif
                            {!! $stateTag($candidate['state']) !!}
                            <div class="d">{{ $image->image_code }}</div>
                            <div class="d">{{ $spec['model'] ?? '' }} · {{ $spec['size'] ?? '' }} · {{ $spec['quality'] ?? '' }} · {{ $image->created_at?->format('Y-m-d H:i') }}</div>
                            @if($candidate['task'] !== null)
                                <div class="va-brief">@include('video-projects.partials.task-state', ['task' => $candidate['task'], 'projectId' => $id])</div>
                            @endif
                            @if($candidate['error'] !== null)
                                <div class="d" style="color:var(--vp-red)">{{ $candidate['error'] }}</div>
                            @endif
                            @if($candidate['problem'] !== null)
                                <div class="d" style="color:var(--vp-amber-fg)">{{ $candidate['problem'] }}</div>
                            @endif
                            @if($candidate['file_problem'] !== null)
                                <div class="d" style="color:var(--vp-red)">{{ $candidate['file_problem'] }}</div>
                            @endif
                            @php
                                $frame = $candidate['frame'];
                                $edit = $candidate['edit'];
                                $key = str_replace('-', '', (string) $image->id);
                            @endphp
                            <div class="vk-actions">
                                @if($artifact !== null)
                                    <a class="vp-btn sm" href="{{ route('video-artifacts.show', $artifact->id) }}" target="_blank" rel="noopener">Xem lớn</a>
                                @endif
                                @if($candidate['approvable'] && $frame === null)
                                    <form method="POST" action="{{ route('video-projects.keyframe-action', [$id, $shot['id'], 'approve']) }}" onsubmit="return vpLockForm(this)">
                                        @csrf
                                        <input type="hidden" name="artifact_id" value="{{ $artifact->id }}">
                                        <input type="hidden" name="in_use" value="{{ $row['in_use_decision'] }}">
                                        <button class="vp-btn sm ok" data-busy="Đang duyệt…">✓ Duyệt và dùng ảnh này</button>
                                    </form>
                                @endif
                                @if($candidate['revocable'])
                                    <form method="POST" action="{{ route('video-projects.keyframe-action', [$id, $shot['id'], 'revoke']) }}" onsubmit="return vpLockForm(this)">
                                        @csrf
                                        <input type="hidden" name="artifact_id" value="{{ $artifact->id }}">
                                        <button class="vp-btn sm" data-busy="Đang thu hồi…">↩ Thu hồi duyệt</button>
                                    </form>
                                @endif
                            </div>
                            @if($frame !== null)
                                <div class="vk-frame" data-frame="{{ $artifact->id }}" data-width="{{ $frame['width'] }}" data-height="{{ $frame['height'] }}" data-unit-x="{{ $frame['unit'][0] }}" data-unit-y="{{ $frame['unit'][1] }}" data-k-min="{{ $frame['k_min'] }}" data-k-max="{{ $frame['k_max'] }}" data-delivery="{{ str_replace('x', ' × ', $frame['delivery_size']) }}">
                                    <div class="va-lbl">Khung video — cắt từ ảnh gốc {{ $frame['width'] }} × {{ $frame['height'] }} thành {{ str_replace('x', ' × ', $frame['delivery_size']) }}</div>
                                    @if($frame['can_apply'])
                                        <div class="vk-frame-view">
                                            <div class="vk-frame-stage" data-frame-stage>
                                                <img src="{{ route('video-artifacts.show', $artifact->id) }}" alt="{{ $image->image_code }}" loading="lazy">
                                                <div class="vk-frame-window" data-frame-window></div>
                                            </div>
                                            <div class="vk-frame-preview" data-frame-preview style="aspect-ratio: {{ $frame['unit'][0] }} / {{ $frame['unit'][1] }}; background-image:url('{{ route('video-artifacts.show', $artifact->id) }}')"></div>
                                        </div>
                                        <form method="POST" action="{{ route('video-projects.keyframe-action', [$id, $shot['id'], 'frame']) }}" class="vk-frame-form" onsubmit="return vpLockForm(this)">
                                            @csrf
                                            <input type="hidden" name="artifact_id" value="{{ $artifact->id }}">
                                            <input type="hidden" name="x" data-frame-field="x" value="{{ $frame['default']['x'] }}">
                                            <input type="hidden" name="y" data-frame-field="y" value="{{ $frame['default']['y'] }}">
                                            <input type="hidden" name="width" data-frame-field="width" value="{{ $frame['default']['width'] }}">
                                            <input type="hidden" name="height" data-frame-field="height" value="{{ $frame['default']['height'] }}">
                                            <label class="d">Ngang <input type="range" min="0" max="1000" value="500" data-frame-range="x"></label>
                                            <label class="d">Dọc <input type="range" min="0" max="1000" value="500" data-frame-range="y"></label>
                                            <label class="d">Cỡ khung <input type="range" min="{{ $frame['k_min'] }}" max="{{ $frame['k_max'] }}" value="{{ $frame['k_max'] }}" data-frame-range="k"></label>
                                            <div class="d" data-frame-readout>{{ $frame['default']['width'] }} × {{ $frame['default']['height'] }} tại x {{ $frame['default']['x'] }}, y {{ $frame['default']['y'] }}</div>
                                            <button class="vp-btn sm" data-busy="Đang cắt…">Áp dụng khung</button>
                                        </form>
                                    @elseif($frame['default'] === null)
                                        <div class="d" style="color:var(--vp-red)">Ảnh gốc không đủ tạo ảnh video {{ str_replace('x', ' × ', $frame['delivery_size']) }} mà không phóng lớn — tạo bản khác.</div>
                                    @endif
                                    @foreach($frame['deliveries'] as $delivery)
                                        <div class="vk-delivery">
                                            <a href="{{ route('video-artifacts.show', $delivery['artifact']->id) }}" target="_blank" rel="noopener"><img src="{{ route('video-artifacts.show', $delivery['artifact']->id) }}" alt="Ảnh video" width="{{ $delivery['artifact']->width }}" height="{{ $delivery['artifact']->height }}" loading="lazy"></a>
                                            <div class="vk-delivery-info">
                                                @if($delivery['in_use'])<span class="va-tag ok">Đang dùng cho video</span>@endif
                                                <div class="d">Ảnh video {{ $delivery['artifact']->width }} × {{ $delivery['artifact']->height }}</div>
                                                <div class="d">Vùng {{ $delivery['crop']['width'] ?? '—' }} × {{ $delivery['crop']['height'] ?? '—' }} tại x {{ $delivery['crop']['x'] ?? '—' }}, y {{ $delivery['crop']['y'] ?? '—' }}</div>
                                                @if($delivery['problem'] !== null)
                                                    <div class="d" style="color:var(--vp-red)">{{ $delivery['problem'] }}</div>
                                                @endif
                                                @if($delivery['approvable'])
                                                    <form method="POST" action="{{ route('video-projects.keyframe-action', [$id, $shot['id'], 'approve']) }}" onsubmit="return vpLockForm(this)">
                                                        @csrf
                                                        <input type="hidden" name="artifact_id" value="{{ $artifact->id }}">
                                                        <input type="hidden" name="delivery_id" value="{{ $delivery['artifact']->id }}">
                                                        <input type="hidden" name="in_use" value="{{ $row['in_use_decision'] }}">
                                                        <button class="vp-btn sm ok" data-busy="Đang duyệt…">✓ Duyệt bộ ảnh này</button>
                                                    </form>
                                                @endif
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                            @if($edit !== null && $editForm !== null)
                                @php
                                    $editScreen = $edit['screen'];
                                    $editShown = $editScreen['shown'];
                                    $editWrite = 'kfEdit'.$key;
                                    $editRender = 'kfEditRender'.$key;
                                    $sendable = count($editForm['references']) <= $editForm['limit'] - 1;
                                @endphp
                                <details class="vk-edit" data-keep-open="edit-{{ $key }}">
                                    <summary class="vp-btn sm">Sửa ảnh này</summary>
                                    <div class="va-lbl">Nhóm lỗi cần sửa</div>
                                    <div class="vk-checks">
                                        @foreach($editForm['faults'] as $fault => $label)
                                            <label><input type="checkbox" name="faults[]" value="{{ $fault }}" form="{{ $editWrite }}" data-keep="{{ $key }}"> {{ $label }}</label>
                                        @endforeach
                                    </div>
                                    @if($editForm['targets'] !== [])
                                        <div class="va-lbl">Đối tượng liên quan (không bắt buộc)</div>
                                        <div class="vk-checks">
                                            @foreach($editForm['targets'] as $target)
                                                <label><input type="checkbox" name="targets[]" value="{{ $target['type'] }}:{{ $target['id'] }}" form="{{ $editWrite }}" data-keep="{{ $key }}"> {{ $target['label'] }}</label>
                                            @endforeach
                                        </div>
                                    @endif
                                    <div class="va-lbl">Ảnh gửi kèm — ảnh cần sửa và tối đa {{ $editForm['limit'] - 1 }} ảnh tham chiếu</div>
                                    <div class="vk-checks" data-edit-references="{{ $editForm['limit'] - 1 }}">
                                        @foreach($editForm['references'] as $reference)
                                            <label><input type="checkbox" name="references[]" value="{{ $reference['artifact_id'] }}" form="{{ $editWrite }}" data-keep="{{ $key }}" @checked($sendable)> {{ $reference['code'] }} · {{ $reference['kind'] }}</label>
                                        @endforeach
                                    </div>
                                    <div class="d" data-edit-count></div>
                                    <input class="ctl" type="text" name="note" maxlength="{{ \App\Video\Moto\MotoKeyframePrompt::NOTE_MAX_CHARS }}" placeholder="Ghi chú ngắn (không bắt buộc)" form="{{ $editWrite }}" data-keep="{{ $key }}">
                                    <form method="POST" action="{{ route('video-projects.keyframe-action', [$id, $shot['id'], 'edit-prompt']) }}" id="{{ $editWrite }}" data-modal="confirm{{ $editWrite }}" onsubmit="return vpLockForm(this)" hidden>
                                        @csrf
                                        <input type="hidden" name="artifact_id" value="{{ $artifact->id }}">
                                        <input type="hidden" name="operation_id" value="{{ $editScreen['operation_id'] }}">
                                        <input type="hidden" name="confirm_cost" value="1">
                                    </form>
                                    <div class="vk-actions">
                                        <button type="button" class="vp-btn sm pri" data-toggle="modal" data-target="#confirm{{ $editWrite }}" data-busy="Đang gửi…"
                                                @if(! $editScreen['can_write']) disabled title="Đang có lượt viết chờ hoặc đang chạy" @endif>{{ $editShown !== null ? 'Viết lại prompt sửa' : 'Viết prompt sửa bằng AI' }}</button>
                                    </div>
                                    @include('modal.confirm_action', ['id' => 'confirm'.$editWrite, 'form' => $editWrite, 'content' => 'Gọi AI một lần để viết prompt sửa cho '.$image->image_code.' từ ảnh cần sửa, dữ liệu shot và các ảnh tham chiếu đã chọn — TÁC VỤ NÀY TÍNH TIỀN. Viết xong không tự duyệt, không render, ảnh đang dùng không đổi.', 'detail' => $writer['estimate'] !== null ? 'Dự toán gần bằng một lượt viết prompt ảnh của shot: '.$writer['estimate']['text'].'.' : 'Chưa có dự toán cho lượt này.'])
                                    @if($editScreen['running'])
                                        <div class="d"><span class="dot"></span>Đang viết prompt sửa…</div>
                                        <div class="va-brief">@include('video-projects.partials.stream-state', ['stream' => $editScreen['stream']])</div>
                                    @elseif($editScreen['pending'])
                                        <div class="d">Đang chờ máy chạy nền nhận lượt viết.</div>
                                    @elseif($editScreen['error'] !== null)
                                        <div class="d" style="color:var(--vp-red)">{{ $editScreen['error'] }}</div>
                                    @endif
                                    @if($editShown !== null)
                                        <div class="va-lbl">Prompt sửa — AI viết, gửi nguyên văn bản đã duyệt</div>
                                        <div class="d">Yêu cầu: {{ implode(', ', array_map(static fn (string $fault): string => $faultLabels[$fault] ?? $fault, (array) ($editScreen['request']['faults'] ?? []))) }}@if(($editScreen['request']['targets'] ?? []) !== []) · {{ implode(', ', array_map(static fn (array $target): string => $target['id'], (array) $editScreen['request']['targets'])) }}@endif @if(($editScreen['request']['note'] ?? null) !== null) · “{{ $editScreen['request']['note'] }}”@endif</div>
                                        <textarea class="va-ta" readonly>{{ $editShown['prompt'] }}</textarea>
                                        <div class="d">Lượt {{ $editShown['revision'] }} · {{ $editShown['verdict'] ?? '—' }} · {{ $editShown['status'] ?? '—' }} · {{ $editShown['model'] }} @if($editShown['cost_usd'] !== null) · ${{ number_format($editShown['cost_usd'], 4) }}@endif @if($editShown['selected']) · đã duyệt @endif</div>
                                        @if($editShown['error'] !== null)
                                            <div class="d" style="color:var(--vp-red)">{{ $editShown['error'] }}</div>
                                        @endif
                                        @foreach([...$editShown['schema'], ...$editShown['issues']] as $issue)
                                            <div class="d" style="color:var(--vp-red)">{{ $issue }}</div>
                                        @endforeach
                                        @foreach($editShown['gaps'] as $gap)
                                            <div class="d" style="color:var(--vp-amber-fg)">Thiếu dữ kiện: {{ $gap['missing'] }} — {{ $gap['why'] }}</div>
                                        @endforeach
                                        @foreach($editShown['conflicts'] as $conflict)
                                            <div class="d" style="color:var(--vp-amber-fg)">Mâu thuẫn ({{ implode(' / ', $conflict['sources']) }}): {{ $conflict['what'] }}</div>
                                        @endforeach
                                        @foreach($editShown['flags'] as $flag)
                                            <div class="d" style="color:var(--vp-dim)">Ghi chú của AI: {{ $flag }}</div>
                                        @endforeach
                                        @if(! $editShown['current'])
                                            <div class="d" style="color:var(--vp-amber-fg)">Lượt này viết từ nguồn cũ</div>
                                        @endif
                                        @if($editShown['approvable'])
                                            <form method="POST" action="{{ route('video-projects.keyframe-action', [$id, $shot['id'], 'prompt-approve']) }}" onsubmit="return vpLockForm(this)">
                                                @csrf
                                                <input type="hidden" name="stage_id" value="{{ $editShown['id'] }}">
                                                <button class="vp-btn sm ok" data-busy="Đang duyệt…">✓ Duyệt prompt sửa này</button>
                                            </form>
                                        @endif
                                    @endif
                                    @if($edit['prompt'] !== null)
                                        <div class="d">Prompt sửa đang dùng: lượt {{ substr($edit['prompt']['stage_id'], 0, 8) }} · gửi {{ count($edit['sources']) }} ảnh (ảnh cần sửa ở vị trí 1)</div>
                                        @foreach($edit['problems'] as $problem)
                                            <div class="d" style="color:var(--vp-amber-fg)">{{ $problem }}</div>
                                        @endforeach
                                        <form method="POST" action="{{ route('video-projects.keyframe-action', [$id, $shot['id'], 'edit-render']) }}" id="{{ $editRender }}" data-modal="confirm{{ $editRender }}" onsubmit="return vpLockForm(this)" hidden>
                                            @csrf
                                            <input type="hidden" name="artifact_id" value="{{ $artifact->id }}">
                                            <input type="hidden" name="stage_id" value="{{ $edit['prompt']['stage_id'] }}">
                                            <input type="hidden" name="preview_hash" value="{{ $edit['preview_hash'] }}">
                                            <input type="hidden" name="operation_id" value="{{ \Illuminate\Support\Str::uuid() }}">
                                            <input type="hidden" name="confirm_cost" value="1">
                                        </form>
                                        <div class="vk-actions">
                                            <button type="button" class="vp-btn sm pri" data-toggle="modal" data-target="#confirm{{ $editRender }}" data-busy="Đang gửi…"
                                                    @if(! $edit['renderable'] || $row['busy']) disabled title="{{ $row['busy'] ? 'Shot đang có một lượt chờ hoặc đang render' : implode(' · ', $edit['problems']) }}" @endif>Render bản sửa</button>
                                        </div>
                                        @include('modal.confirm_action', ['id' => 'confirm'.$editRender, 'form' => $editRender, 'content' => 'Gửi prompt sửa đã duyệt kèm ảnh cần sửa và '.max(0, count($edit['sources']) - 1).' ảnh tham chiếu theo '.$settings['model'].' · '.($settings['quality'] !== '' ? $settings['quality'].' · ' : '').$settings['size'].': một request, một ảnh mới — TÁC VỤ NÀY TÍNH TIỀN. Ảnh cần sửa và ảnh đang dùng không bị ghi đè.', 'detail' => 'Kết quả là một ứng viên mới của '.$shot['id'].'.'])
                                    @endif
                                </details>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endforeach
@unless($fragment)
</div>
@endunless
