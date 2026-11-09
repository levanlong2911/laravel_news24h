@php
    $panel = $motoKeyframes;
    $states = $panel['states'];
    $pixels = static fn (string $size): string => str_replace('x', ' × ', $size);
    $stateTag = static fn (string $state): string => '<span class="va-tag '.($states[$state][0] ?? 'mute').'">'.e($states[$state][1] ?? $state).'</span>';
@endphp

<div class="container-fluid vp" id="keyframesPage">

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
            <em>khung đầu của từng shot — prompt nguyên văn từ kế hoạch shot đã duyệt, ảnh tham chiếu nguyên tấm</em>
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
                @endif
            </div>
        </div>
    </div>

    @foreach($panel['rows'] as $row)
        @php
            $shot = $row['shot'];
            $camera = $shot['camera'];
            $settings = $row['settings'];
            $renderForm = 'kfRender'.$shot['id'];
            $settingsForm = 'kfSettings'.$shot['id'];
            $promptBox = 'kfPrompt'.$shot['id'];
            $used = collect($row['candidates'])->firstWhere('revocable', true);
        @endphp
        <div class="vs-shot-detail" id="shot-{{ $shot['id'] }}">
            <div class="vs-shot-grid">
                <div class="vs-h"><b>1</b> Planning</div>
                <div class="vs-h"><b>2</b> Prompt</div>
                <div class="vs-h"><b>3</b> References</div>
                <div class="vs-h"><b>4</b> Image</div>

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
                    <div class="d">{{ $camera['scale'] }} · {{ $camera['lens_mm'] }} mm · cao {{ $camera['height_m'] ?? '—' }} m · cách {{ $camera['distance_m'] ?? '—' }} m · azimuth {{ $camera['azimuth_deg'] ?? '—' }}° · elevation {{ $camera['elevation_deg'] ?? '—' }}° · {{ $camera['move'] }}{{ $camera['move_value'] !== null ? ' '.$camera['move_value'].' '.$camera['move_unit'] : '' }}</div>
                    <div class="d">Đầu: {{ $camera['start_pose'] ?? '—' }}</div>
                    <div class="d">Cuối: {{ $camera['end_pose'] ?? '—' }}</div>
                    <div class="va-lbl">Prompt ảnh (keyframe) — gửi nguyên văn</div>
                    <textarea class="va-ta" id="{{ $promptBox }}" readonly>{{ $shot['prompts']['image'] }}</textarea>
                    <div class="va-count"><span>{{ mb_strlen($shot['prompts']['image']) }}</span> ký tự</div>
                    <button type="button" class="vp-btn sm" data-copy-prompt="{{ $promptBox }}">Sao chép prompt</button>
                    <details class="vk-group">
                        <summary>Prompt video (clip) — chỉ để tra cứu</summary>
                        <div class="body">{{ $shot['prompts']['video'] }}</div>
                    </details>
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
                                    <div class="vs-slot filled {{ $card['problem'] === null ? 'sent' : '' }}" title="{{ $card['source']['code'] }} · {{ $card['source']['sha256'] }}">
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
                                @if($card['parts'] !== [])
                                    <div class="d">Part: {{ implode(', ', $card['parts']) }}</div>
                                @endif
                                @if(isset($card['detail']))
                                    <div class="d">{{ $card['detail'] }}</div>
                                @endif
                                @if(isset($card['source']['master_code']))
                                    <div class="d">Từ master: {{ $card['source']['master_code'] }}</div>
                                @endif
                                @if($card['problem'] !== null)
                                    <div class="d" style="color:var(--vp-amber-fg)">{{ $card['problem'] }}</div>
                                @elseif(! $card['empty'])
                                    <div class="d">{{ $card['status'] }}</div>
                                @endif
                                @if(! $card['empty'] && $card['options'] === [])
                                    <button type="button" class="vp-btn sm" disabled title="Không có ảnh khác dùng được">Đổi ảnh</button>
                                @elseif(! $card['empty'])
                                    <details class="vk-swap">
                                        <summary class="vp-btn sm">Đổi ảnh</summary>
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
                            </div>
                        @endforeach
                    </div>

                    <form method="POST" action="{{ route('video-projects.keyframe-action', [$id, $shot['id'], 'settings']) }}" id="{{ $settingsForm }}" onsubmit="return vpLockForm(this)">
                        @csrf
                        <div class="vk-controls">
                            <div class="va-field">
                                <label>Model</label>
                                <select class="ctl" name="model" required onchange="this.form.requestSubmit()">
                                    @foreach($settings['models'] as $entry)
                                        <option value="{{ $entry['model'] }}" @selected($entry['model'] === $settings['model'])>{{ $entry['label'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="va-field">
                                <label>Quality</label>
                                <select class="ctl" name="quality" required onchange="this.form.requestSubmit()">
                                    @foreach($settings['qualities'] as $quality)
                                        <option value="{{ $quality }}" @selected($quality === $settings['quality'])>{{ $quality }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="va-field">
                                <label>Size <em>({{ $panel['aspect_ratio'] }})</em></label>
                                <select class="ctl" name="size" required onchange="this.form.requestSubmit()">
                                    @foreach($settings['sizes'] as $size)
                                        <option value="{{ $size }}" @selected($size === $settings['size'])>{{ $pixels($size) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="va-field"><label>Số ảnh <em>(cố định)</em></label><select class="ctl" disabled><option>1</option></select></div>
                        </div>
                    </form>
                    <div class="d">
                        @if($row['price'] !== null)
                            Dự toán: ${{ number_format((float) $row['price']['usd'], 3) }} / ảnh (bảng giá {{ $row['price']['version'] }}, ước tính, chưa phản ánh size)
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
                    <div class="vk-actions">
                        <button type="button" class="vp-btn pri" data-toggle="modal" data-target="#confirm{{ $renderForm }}" data-busy="Đang gửi…"
                                @if(! $row['renderable']) disabled title="{{ $row['busy'] ? 'Shot đang có một lượt chờ hoặc đang render' : implode(' · ', $row['problems']) }}" @endif>{{ $row['has_candidates'] ? 'Tạo bản khác' : 'Render Image' }}</button>
                    </div>
                    @include('modal.confirm_action', ['id' => 'confirm'.$renderForm, 'form' => $renderForm, 'content' => 'Gửi prompt keyframe của '.$shot['id'].' kèm '.count($row['sources']).' ảnh nguyên tấm cho '.$settings['model'].', '.$settings['size'].', '.$settings['quality'].': một request, một ảnh — TÁC VỤ NÀY TÍNH TIỀN.', 'detail' => 'Xem dự toán ở cột References.'])
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
                            <div class="vk-actions">
                                @if($artifact !== null)
                                    <a class="vp-btn sm" href="{{ route('video-artifacts.show', $artifact->id) }}" target="_blank" rel="noopener">Xem lớn</a>
                                @endif
                                @if($candidate['approvable'])
                                    <form method="POST" action="{{ route('video-projects.keyframe-action', [$id, $shot['id'], 'approve']) }}" onsubmit="return vpLockForm(this)">
                                        @csrf
                                        <input type="hidden" name="artifact_id" value="{{ $artifact->id }}">
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
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endforeach
</div>
