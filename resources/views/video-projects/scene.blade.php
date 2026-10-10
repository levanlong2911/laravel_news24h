@extends('layouts.base', ['title' => 'Storyboard'])
@section('title', 'Storyboard')

@section('css')
<link rel="stylesheet" href="{{ asset('assets/css/video-producer.css') }}?v={{ filemtime(public_path('assets/css/video-producer.css')) }}">
@endsection

@section('content')

<div class="container-fluid vp">

    <div class="vp-crumb">
        <a href="{{ route('video-projects.index') }}">Video Projects</a>
        <span class="sep">/</span>
        <a href="{{ route('video-projects.anchor', $id) }}">Ảnh neo</a>
        <span class="sep">·</span>
        <a href="{{ route('video-projects.reference', $id) }}">Reference Views</a>
        <span class="sep">·</span>
        <a href="{{ route('video-projects.environment', $id) }}">Environment Library</a>
        <span class="sep">·</span>
        <b>Storyboard</b>
        <span class="sep">·</span>
        <a href="{{ route('video-projects.render-video', $id) }}"><b>Render video →</b></a>
        <span class="grow"></span>
        <span class="m">{{ count($storyboard['scenes']) }} scene · {{ count($scenes) }} shot</span><span class="sep">·</span>
        <span class="m">Đã render <b>{{ $summary['approved'] }}</b>/{{ count($scenes) }}</span>
        @if($revision > 0)
            <span class="sep">·</span><span class="m">Bản <b>{{ $revision }}</b></span>
            <span class="sep">·</span>
            <span class="m" style="color:{{ $review['status'] === 'passed' ? 'var(--vp-green-fg)' : 'var(--vp-amber-fg)' }}">
                <b>{{ $review['status'] === 'passed' ? ($review['reason'] === 'validated' ? 'Đã kiểm bằng code' : 'Đã rà tự động') : 'Chưa kiểm' }}</b>
            </span>
        @endif
        @isset($review['usage']['total']['calls'])
            <span class="sep">·</span>
            <span class="m">{{ $review['usage']['total']['calls'] }} lượt AI ·
                {{ $review['usage']['total']['tokens_in'] ?? 0 }} token vào ·
                {{ $review['usage']['total']['tokens_out'] ?? 0 }} token ra</span>
        @endisset
        @if($records > 0)
            <span class="sep">·</span>
            <span class="m">Lập storyboard: <b>{{ $records }}</b> bản ghi xử lý</span>
            @if($unpriced)
                <span class="sep">·</span><span class="m">Chưa định giá</span>
            @endif
        @endif

        <form method="POST" action="{{ route('video-projects.storyboard-generate', $id) }}"
              id="storyboardForm" data-modal="confirmStoryboard" hidden>
            @csrf
        </form>

        @if($approval['shot_board'])
            <span class="sep">·</span>
            @if($approval['approved'])
                <span class="m" style="color:var(--vp-green-fg)"><b>Đã duyệt — mở render</b></span>
            @else
                <span class="m" style="color:var(--vp-amber-fg)"><b>Bản nháp — chưa render được</b>
                    @if($approval['selected_revision'] > 0) (đang dùng bản {{ $approval['selected_revision'] }}) @endif
                </span>
                @if($review['status'] === 'passed')
                    <button type="button" class="vp-btn sm" id="storyboardApprove"
                            data-action="{{ route('video-projects.storyboard-approve', $id) }}"
                            data-revision="{{ $revision }}">Duyệt storyboard</button>
                @endif
            @endif
        @endif

        <button type="button" class="vp-btn sm pri" data-toggle="modal" data-target="#confirmStoryboard"
                id="storyboardButton" @disabled(($storyboardRun['status'] ?? null) === 'running')>{{ $revision > 0 ? 'Lập lại storyboard' : 'Lập storyboard' }}</button>

        @include('modal.confirm_action', [
            'id' => 'confirmStoryboard',
            'form' => 'storyboardForm',
            'content' => 'Gọi AI một lượt cho toàn bộ '.count($storyboard['scenes']).' scene của kịch bản production — TÁC VỤ NÀY TÍNH TIỀN.',
            'detail' => $revision > 0
                ? 'Tạo bản '.($revision + 1).' mới thay cho bản '.$revision.' trên trang này: ảnh và clip của bản cũ KHÔNG được chuyển sang, mọi shot phải render và duyệt lại; bản mới là bản nháp, phải duyệt storyboard mới render được. Bản cũ vẫn giữ trong lịch sử. Có thể mất vài phút; đừng đóng trang.'
                : 'AI chia beat thành shot theo mục đích quan sát, viết góc máy, ảnh tĩnh, hành động, thời lượng và trạng thái vật. Kết quả là bản nháp — duyệt storyboard rồi mới render. Có thể mất vài phút; đừng đóng trang.',
        ])
    </div>

    <div class="vs-c" id="storyboardStatus" style="margin-bottom:10px;{{ in_array($storyboardRun['status'] ?? null, ['running', 'interrupted', 'failed'], true) ? '' : 'display:none;' }}color:{{ ($storyboardRun['status'] ?? null) === 'failed' ? 'var(--vp-red)' : 'var(--vp-amber-fg)' }}">
        @switch($storyboardRun['status'] ?? null)
            @case('running')
                Đang có một lượt lập storyboard chạy (cập nhật lúc {{ $storyboardRun['at'] }}). Tải lại trang sau ít phút để xem kết quả.
                @break
            @case('interrupted')
                Lượt lập storyboard lúc {{ $storyboardRun['at'] }} bị dừng giữa chừng — chưa xác định kết quả. Nếu AI trả về muộn, kết quả được ghi lại nhưng không dùng.
                @break
            @case('failed')
                Lượt lập storyboard lúc {{ $storyboardRun['at'] }} thất bại: {{ $storyboardRun['error'] }}
                @break
        @endswitch
    </div>

    @if($profileNotice !== null)
        <div class="vs-c" style="margin-bottom:10px;color:var(--vp-amber-fg)">
            Không xác minh được profile của bản {{ $revision }} — nhãn mốc đang hiện ở dạng khoá gốc.
        </div>
    @endif

    @if($preservationNotice !== null)
        <div class="vs-c" style="margin-bottom:10px;color:var(--vp-amber-fg)">
            Bản {{ $revision }} khai một phiên bản khối bảo toàn không nhận ra —
            không dựng được prompt ảnh. Danh sách scene và prompt clip vẫn đọc được.
        </div>
    @endif

    @if($warnings !== [])
        <div class="vs-c" style="margin-bottom:10px;color:var(--vp-amber-fg)">
            <b>Cảnh báo của bản {{ $revision }}</b> — heuristic, không phải lỗi hợp đồng:
            <ul style="margin:6px 0 0 18px">
                @foreach($warnings as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="vs-grid" id="storyboardGrid">

        <div class="vs-h"><b>1</b> Planning</div>
        <div class="vs-h"><b>2</b> Prompt</div>
        <div class="vs-h"><b>3</b> References</div>
        <div class="vs-h"><b>4</b> Image</div>

        @forelse($scenes as $s)
            @if($s['screenplay_scene_code'] !== null && ($loop->first || ($scenes[$loop->index - 1]['screenplay_scene_code'] ?? null) !== $s['screenplay_scene_code']))
                <div class="vs-c" style="grid-column:1/-1;font-weight:600">
                    Scene {{ strtoupper($s['screenplay_scene_code']) }}
                </div>
            @endif
            <div class="vs-row">

                <div class="vs-c vs-plan">
                    <span class="o">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                    <span class="t">{{ $s['title'] }}</span>
                    <span class="ph">Shot {{ $s['shot_index'] }}</span>
                    <span class="ph">{{ $s['phase'] }}</span>

                    @if($s['shot_board'])
                        @foreach($s['beat_coverage'] as $covered)
                            <span class="ph" title="Phần beat shot này thể hiện">{{ $covered['beat_id'] ?? '?' }}{{ ['whole' => '', 'opening' => ' · mở', 'middle' => ' · giữa', 'closing' => ' · kết'][$covered['part'] ?? ''] ?? '' }}</span>
                        @endforeach
                        <span class="ph">{{ $s['camera_relation'] === 'same_camera' ? 'giữ máy' : 'máy mới' }}</span>
                        @if($s['duration_seconds'] !== null)
                            <span class="ph" title="Thời lượng clip">{{ $s['duration_seconds'] }}s</span>
                        @endif
                    @else
                        @foreach($s['beat_ids'] as $beatId)
                            <span class="ph" title="Beat của scene">{{ $beatId }}</span>
                        @endforeach
                    @endif

                    @foreach($s['milestones'] as $milestone)
                        <span class="ph">{{ $milestone }}</span>
                    @endforeach

                    @foreach($s['coverage'] as $coverageId)
                        <span class="ph">{{ $coverageId }}</span>
                    @endforeach

                    @if($s['continuity_group'] === null)
                        <div class="m">chưa có nhóm cảnh</div>
                    @else
                        <div class="m">
                            nhóm <b>{{ $s['continuity_group'] }}</b>
                            @if($s['source_scene_code'] !== null)
                                · nguồn <b>{{ $s['source_scene_code'] }}</b>
                            @else
                                · mở nhóm
                            @endif
                        </div>
                        @if($s['camera_change_reason'] !== null)
                            <div class="m">Góc: {{ $s['camera_change_reason'] }}</div>
                        @endif
                    @endif

                    @if($s['basis'] === 'inferred_process')
                        <span class="ph" title="Bài viết không nói bước này — model suy diễn quy trình">suy diễn</span>
                    @endif

                    <div class="d">{{ $s['purpose'] }}</div>

                    @if(! $s['storyboard'] && ($s['state_before'] || $s['scene_state']))
                        <div class="d">
                            {{ $s['state_before'] ?? '?' }} → <b>{{ $s['scene_state'] ?? '?' }}</b>
                            @if($s['end_state']) → {{ $s['end_state'] }} @endif
                        </div>
                    @endif

                    @foreach(['keyframe_state' => 'Khung hình', 'shot_end_state' => 'Cuối clip'] as $stateKey => $stateLabel)
                        @if(is_array($s[$stateKey]))
                            <div class="m">
                                <b>{{ $stateLabel }}:</b> {{ $s[$stateKey]['progress'] ?? '—' }}
                                @foreach((array) ($s[$stateKey]['configuration'] ?? []) as $item)
                                    · {{ $item['part'] ?? '?' }}: {{ $item['state'] ?? '?' }}
                                @endforeach
                            </div>
                        @endif
                    @endforeach

                    @if($s['shot_board'])
                        @foreach(['objects_start' => 'Vật trong khung', 'objects_end' => 'Vật cuối clip'] as $objectKey => $objectLabel)
                            @if($s[$objectKey] !== [])
                                <div class="m">
                                    <b>{{ $objectLabel }}:</b>
                                    @foreach($s[$objectKey] as $object)
                                        {{ $loop->first ? '' : '·' }} {{ $object['name'] ?? '?' }}: {{ $object['state'] ?? '?' }}
                                    @endforeach
                                </div>
                            @endif
                        @endforeach

                        @foreach(['entering' => 'Vào khung', 'leaving' => 'Ra khung'] as $moveKey => $moveLabel)
                            @if($s['object_moves'][$moveKey] !== [])
                                <div class="m"><b>{{ $moveLabel }}:</b> {{ implode(' · ', $s['object_moves'][$moveKey]) }}</div>
                            @endif
                        @endforeach

                        <div class="m">
                            <b>Cần ảnh:</b>
                            @forelse($s['reference_requirements'] as $need)
                                {{ $loop->first ? '' : '·' }} {{ ['design' => 'thiết kế', 'continuity' => 'liền mạch', 'identity' => 'nhận dạng', 'environment' => 'tấm nền'][$need['kind'] ?? ''] ?? '?' }} ({{ $need['purpose'] ?? '' }})
                            @empty
                                không
                            @endforelse
                        </div>

                        @if($s['camera_relation'] === 'new_camera' && ! $loop->first)
                            <div class="m" title="Không trích khung cuối của clip trước">Mối nối với clip trước: chưa xác minh</div>
                        @endif
                    @endif
                </div>

                <div class="vs-c vs-prompt">
                    <div class="vs-lbl">GÓC MÁY</div>
                    <div class="body">{{ $s['camera'] === [] ? ($s['transition_mode'] === 'continuation_edit' ? 'Giữ góc máy của shot trước' : '—') : implode(' · ', $s['camera']) }}</div>

                    @if($s['image_prompt'] !== null)
                        <div class="vs-lbl">ẢNH</div>
                        <div class="body">{{ $s['image_prompt'] }}</div>
                    @endif

                    {{-- @if($s['video_prompt'])
                        <div class="vs-lbl">CLIP — bản nháp, xác nhận lại sau khi duyệt ảnh</div>
                        <div class="body">{{ $s['video_prompt'] }}</div>
                    @endif --}}
                </div>

                @php($refs = $sources[$s['scene_id']] ?? ['slots' => [], 'blocked_reason' => null, 'blocked_code' => null, 'references' => null])
                <div class="vs-c vs-refs">
                    <div id="refs_{{ $s['scene_id'] }}">
                        <div class="vs-slots">
                            @foreach($refs['slots'] as $slot)
                                <div class="vs-slot filled {{ $slot['primary'] ? 'sent' : '' }}"
                                     title="{{ $referenceRoles[$slot['role']] ?? $slot['role'] }} · {{ $slot['title'] }} · {{ $slot['sha'] }}">
                                    <a href="{{ $slot['url'] }}" target="_blank"><img src="{{ $slot['url'] }}" alt="{{ $slot['title'] }}"></a>
                                    <span class="cap">
                                        <b class="n">{{ $slot['position'] + 1 }} GỬI</b>
                                        {{ $slot['title'] }}
                                        @if($slot['primary'])<b>SỬA</b>@endif
                                    </span>
                                    @if($slot['group'] === 'extra' && ($refs['references'] ?? null) !== null)
                                        <span class="kf-slot-tools">
                                            <button type="button" class="kf-slot-tool js-ref-edit" data-scene="{{ $s['scene_id'] }}"
                                                    data-artifact="{{ $slot['artifact_id'] }}" title="Đổi ảnh hoặc vai trò">✏️</button>
                                            <button type="button" class="kf-slot-tool js-ref-delete" data-scene="{{ $s['scene_id'] }}"
                                                    data-artifact="{{ $slot['artifact_id'] }}" title="Bỏ ảnh này">🗑️</button>
                                        </span>
                                    @endif
                                </div>
                            @endforeach

                            @if(($refs['references'] ?? null) !== null)
                                @for($free = 0; $free < $refs['references']['room']; $free++)
                                    <button type="button" class="vs-slot kf-slot-add js-ref-add" data-scene="{{ $s['scene_id'] }}">＋ Thêm ảnh</button>
                                @endfor
                            @endif
                        </div>

                        @if(($refs['references'] ?? null) !== null)
                            @php($refPayload = $refs['references'] + ['url' => route('video-projects.scene-references', [$id, $s['scene_id']]), 'taken' => array_column($refs['slots'], 'artifact_id')])
                            <script type="application/json" id="refdata_{{ $s['scene_id'] }}">@json($refPayload)</script>
                        @endif

                        @if($refs['blocked_reason'])
                            <div class="m" style="margin-top:6px;color:var(--vp-amber-fg)">
                                {{ $refs['blocked_reason'] }}
                                @if(str_starts_with((string) ($refs['blocked_code'] ?? ''), 'environment_'))
                                    <a href="{{ route('video-projects.environment', $id) }}">Environment Library →</a>
                                @endif
                                @if(($refs['reference_reset'] ?? null) !== null)
                                    <button type="button" class="vp-btn sm js-ref-reset" data-scene="{{ $s['scene_id'] }}"
                                            data-url="{{ $refs['reference_reset']['url'] }}"
                                            data-version="{{ $refs['reference_reset']['version'] }}">Về gợi ý tự động</button>
                                @endif
                            </div>
                        @endif
                    </div>

                    @php($cell = $keyframes[$s['scene_id']] ?? ['approved' => null, 'candidate' => null])
                    @php($kfModels = $keyframeModels[0])
                    @php($kfModelsError = $keyframeModels[1])
                    @php($kfDefault = collect($kfModels)->firstWhere('default', true) ?? ($kfModels[0] ?? null))
                    @if(! $cell['candidate'])
                        @if($kfModelsError !== null || $kfDefault === null)
                            <div class="m" style="margin-top:6px;color:var(--vp-red)">{{ __('messages.scene_keyframe_'.($kfModelsError ?? 'media_invalid')) }}</div>
                        @else
                            <div class="kf-grid">
                                <div class="va-field">
                                    <label>Model</label>
                                    <select class="ctl" data-row="{{ $s['scene_id'] }}" data-role="kf-model">
                                        @foreach($kfModels as $entry)
                                            <option value="{{ $entry['id'] }}" @selected($entry['id'] === $kfDefault['id'])>{{ $entry['label'] }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                @foreach($kfModels as $entry)
                                    <div data-row="{{ $s['scene_id'] }}" data-kf-group="{{ $entry['id'] }}" @unless($entry['id'] === $kfDefault['id']) hidden @endunless>
                                        <div class="va-field">
                                            <label>Quality</label>
                                            <select class="ctl" data-role="kf-quality" @disabled($entry['id'] !== $kfDefault['id'])>
                                                @foreach($entry['controls']['qualities'] as $quality)
                                                    <option value="{{ $quality }}" data-usd="{{ \App\Enums\ImageQuality::tryFrom($quality)?->estimatedCostUsd() }}"
                                                            @selected($quality === $entry['controls']['default_quality'])>{{ $quality }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="va-field">
                                            <label>Size</label>
                                            <select class="ctl" data-role="kf-size" @disabled($entry['id'] !== $kfDefault['id'])>
                                                @foreach($entry['controls']['sizes'] as $size)
                                                    <option value="{{ $size }}" @selected($size === $entry['controls']['default_size'])>{{ $size }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    </div>
                                @endforeach
                                <button type="button" class="vp-btn pri sm js-kf"
                                        data-scene="{{ $s['scene_id'] }}"
                                        data-preview="{{ route('video-projects.scene-keyframe-preview', [$id, $s['scene_id']]) }}"
                                        data-render="{{ route('video-projects.scene-keyframe-render', [$id, $s['scene_id']]) }}">
                                    {{ $cell['approved'] ? 'Tạo bản khác' : 'Xem trước → Render' }}
                                </button>
                                <div class="m kf-wide" data-row="{{ $s['scene_id'] }}" data-role="kf-estimate"></div>
                            </div>
                        @endif
                    @endif
                </div>

                <div class="vs-c vs-img" data-scene="{{ $s['scene_id'] }}">
                    <div class="m kf-result" data-result="{{ $s['scene_id'] }}" hidden></div>

                    @if($cell['approved'])
                        @php($chosen = collect($cell['approved']['artifacts'])
                            ->firstWhere('id', $cell['approved']['selected_artifact_id']))
                        <div class="vs-lbl">ĐÃ DUYỆT</div>
                        @if($cell['approved']['needs_review'] !== null)
                            <div class="m" style="color:var(--vp-amber-fg)">Cần kiểm tra lại: nguồn {{ $cell['approved']['needs_review'] }} đã đổi — render và duyệt lại.</div>
                        @endif
                        @if($cell['approved']['cost_recorded_has_ledger'])
                            <div class="m">@include('video-projects.partials.cost-recorded', ['cell' => $cell['approved']])</div>
                        @endif
                        @if($chosen)
                            <a class="frame" href="{{ $chosen['url'] }}" target="_blank">
                                <img src="{{ $chosen['url'] }}" alt="">
                            </a>
                            {{-- <div class="m vp-mono">{{ $chosen['sha'] }}</div> --}}
                        @endif
                    @endif

                    @if($cell['candidate'])
                        @php($c = $cell['candidate'])
                        <div class="vs-lbl">ỨNG VIÊN · {{ $c['status_label'] }}</div>

                        @if($c['cost_recorded_has_ledger'])
                            <div class="m">@include('video-projects.partials.cost-recorded', ['cell' => $c])</div>
                        @endif

                        @if($c['render_error'])
                            <div class="m" style="color:var(--vp-amber-fg)">{{ $c['render_error'] }}</div>
                        @endif

                        @foreach($c['artifacts'] as $artifact)
                            <form method="POST" class="vs-cand js-kf-action" data-scene="{{ $s['scene_id'] }}" data-busy="Đang duyệt…"
                                  action="{{ route('video-projects.scene-keyframe-approve', [$id, $c['id']]) }}">
                                @csrf
                                <input type="hidden" name="artifact_id" value="{{ $artifact['id'] }}">
                                <a class="frame" href="{{ $artifact['url'] }}" target="_blank">
                                    <img src="{{ $artifact['url'] }}" alt="">
                                </a>
                                <span class="m vp-mono">{{ $artifact['sha'] }}</span>
                                <button class="vp-btn ok sm">✓ Duyệt</button>
                            </form>
                        @endforeach

                        @if($c['resumable'])
                            <form method="POST" class="js-kf-action" data-scene="{{ $s['scene_id'] }}" data-busy="Đang render…"
                                  action="{{ route('video-projects.scene-keyframe-retry', [$id, $c['id']]) }}">
                                @csrf
                                <input type="hidden" name="prompt_sha256" value="{{ $c['prompt_sha256'] }}">
                                <button class="vp-btn sm">
                                    {{ $c['status'] === 'failed' ? '↻ Thử lại' : '▸ Tiếp tục render' }}
                                </button>
                            </form>
                        @endif
                    @elseif(! $cell['approved'])
                        <div class="frame">chưa render</div>
                    @endif
                </div>

            </div>
        @empty
            @forelse($storyboard['scenes'] as $row)
                <div class="vs-row">
                    <div class="vs-c vs-plan">
                        <span class="o">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                        <span class="t">Scene {{ strtoupper($row['id']) }}</span>
                        <span class="ph">{{ $row['location'] }}</span>
                    </div>
                    <div class="vs-c" style="color:var(--vp-dim)">chưa có shot</div>
                    <div class="vs-c" style="color:var(--vp-dim)">—</div>
                    <div class="vs-c" style="color:var(--vp-dim)">—</div>
                </div>
            @empty
                <div class="vs-c" style="grid-column:1/-1;color:var(--vp-red)">
                    Chưa có kịch bản production được duyệt và chọn — duyệt kịch bản ở trang Ảnh neo trước.
                </div>
            @endforelse
        @endforelse

    </div>
</div>

<div class="modal fade" id="kfModal" data-backdrop="false">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-body">
        <div id="kfError" class="vs-c" style="display:none;color:var(--vp-amber-fg)"></div>
        <div id="kfBody" style="display:none">
          <div id="kfSpace" style="display:none">
            <div class="vs-lbl">NGUỒN HÌNH HỌC CHO KHÔNG GIAN <span id="kfSpaceName"></span></div>
            <div class="m">Chọn ảnh thể hiện được không gian này. Ảnh đã duyệt và đúng checksum chưa chắc đủ thông tin — chỉ chọn khi ảnh thật sự cho thấy không gian cần dựng.</div>
            <div id="kfSpaceOptions" style="display:flex;flex-wrap:wrap;gap:8px;margin:6px 0"></div>
            <div id="kfSpaceBlocked" class="vs-c" style="display:none;color:var(--vp-amber-fg)">Chưa có ảnh phù hợp — cảnh này chưa render được. Cần render và duyệt một reference view thể hiện được không gian này trước.</div>
          </div>

          <div class="vs-lbl">PROMPT SẼ GỬI</div>
          <pre id="kfPrompt" class="body" style="white-space:pre-wrap;max-height:34vh;overflow:auto"></pre>

          <div class="vs-lbl">ẢNH THAM CHIẾU — thứ tự này là hợp đồng gửi đi</div>
          <div class="m" id="kfRefNote"></div>
          <table class="vs-src"><tbody id="kfSources"></tbody></table>

          <div class="m" id="kfCost" style="margin-top:8px"></div>
        </div>
      </div>
      <div class="modal-footer justify-content-between">
        <button type="button" class="btn button-back" data-dismiss="modal">{{ __('modal.cancel') }}</button>
        <form method="POST" id="kfForm">
          @csrf
          <input type="hidden" name="prompt_sha256" id="kfHash">
          <input type="hidden" name="anchor_artifact_id" id="kfAnchor">
          <input type="hidden" name="space_source_artifact_id" id="kfSpaceSource">
          <input type="hidden" name="provider_model" id="kfModel">
          <input type="hidden" name="size" id="kfSize">
          <input type="hidden" name="quality" id="kfQuality">
          <input type="hidden" name="reference_choice_version" id="kfChoiceVersion">
          <button type="submit" class="btn btn-primary" id="kfGo" disabled>Render →</button>
        </form>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="refPicker" data-backdrop="false">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-body">
        <div class="vs-lbl" id="refPickerTitle">CHỌN ẢNH THAM CHIẾU</div>
        <div class="m" id="refPickerHint"></div>
        <div class="kf-pick-grid" id="refPickerGrid"></div>
        <div class="m" id="refPickerError" style="display:none;color:var(--vp-red)"></div>
      </div>
      <div class="modal-footer justify-content-between">
        <button type="button" class="btn button-back" data-dismiss="modal">{{ __('modal.cancel') }}</button>
        <button type="button" class="btn btn-primary" id="refPickerApprove" disabled>Duyệt ảnh đã chọn</button>
      </div>
    </div>
  </div>
</div>
@endsection

@section('script')
<script src="{{ asset('assets/js/video-producer.js') }}?v={{ filemtime(public_path('assets/js/video-producer.js')) }}"></script>
<script src="{{ asset('assets/js/keyframe-preview-gate.js') }}?v={{ filemtime(public_path('assets/js/keyframe-preview-gate.js')) }}"></script>
<script>
(function () {
    var form = document.getElementById('storyboardForm');
    var button = document.getElementById('storyboardButton');
    var status = document.getElementById('storyboardStatus');

    if (form === null || button === null || status === null) { return; }

    function show(text, color) {
        status.style.display = '';
        status.style.color = color;
        status.textContent = text;
    }

    function notify(text, ok) {
        if (window.toastr) { window.toastr[ok ? 'success' : 'error'](text); }
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();

        if (window.jQuery) { window.jQuery('#' + form.dataset.modal).modal('hide'); }
        button.disabled = true;
        button.textContent = 'Đang lập storyboard…';
        show('Đang lập storyboard — một lượt AI cho toàn bộ scene, có thể mất vài phút. Đừng đóng trang.', 'var(--vp-amber-fg)');

        fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (response) { return response.json(); })
            .then(function (payload) {
                var text = payload.message || 'Không lập được storyboard.';

                if (payload.ok === true) {
                    notify(text, true);
                    window.location.reload();

                    return;
                }

                button.disabled = false;
                button.textContent = 'Lập storyboard';
                show(text, 'var(--vp-red)');
                notify(text, false);
            })
            .catch(function () {
                button.textContent = 'Lập storyboard';
                show('Chưa xác định kết quả: mất kết nối hoặc không đọc được câu trả lời. Lượt AI có thể vẫn đang chạy hoặc đã xong — tải lại trang để xem trạng thái, đừng bấm lại ngay.', 'var(--vp-amber-fg)');
                notify('Chưa xác định kết quả — tải lại trang để xem trạng thái.', false);
            });
    });

    var approveButton = document.getElementById('storyboardApprove');

    if (approveButton === null) { return; }

    approveButton.addEventListener('click', function () {
        var body = new FormData(form);
        body.append('revision', approveButton.dataset.revision);
        approveButton.disabled = true;

        fetch(approveButton.dataset.action, {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (response) { return response.json(); })
            .then(function (payload) {
                var text = payload.message || 'Không duyệt được storyboard.';
                notify(text, payload.ok === true);

                if (payload.ok === true) {
                    window.location.reload();

                    return;
                }

                approveButton.disabled = false;
                show(text, 'var(--vp-red)');
            })
            .catch(function () {
                approveButton.disabled = false;
                show('Không đọc được kết quả duyệt — tải lại trang để xem trạng thái.', 'var(--vp-amber-fg)');
            });
    });
})();

(function () {
    var picker = document.getElementById('refPicker');

    if (picker === null) { return; }

    var grid = document.getElementById('refPickerGrid');
    var approve = document.getElementById('refPickerApprove');
    var errorBox = document.getElementById('refPickerError');
    var roleLabels = @json($referenceRoles);
    var state = null;

    function token() {
        return document.querySelector('#kfForm [name="_token"]').value;
    }

    function notify(text, ok) {
        if (window.toastr) { window.toastr[ok ? 'success' : 'error'](text); }
    }

    function dataOf(sceneId) {
        var element = document.getElementById('refdata_' + sceneId);
        return element ? JSON.parse(element.textContent) : null;
    }

    function refresh(sceneId) {
        return fetch(window.location.href, { credentials: 'same-origin', headers: { 'Accept': 'text/html' } })
            .then(function (response) { return response.text(); })
            .then(function (html) {
                var fresh = new DOMParser().parseFromString(html, 'text/html').getElementById('refs_' + sceneId);
                var current = document.getElementById('refs_' + sceneId);
                if (fresh && current) { current.replaceWith(fresh); }
            });
    }

    function save(sceneId, url, version, items, reset) {
        var body = new FormData();
        body.append('_token', token());
        body.append('expected_version', version);

        if (reset) {
            body.append('reset', '1');
        } else {
            items.forEach(function (item, index) {
                body.append('items[' + index + '][artifact_id]', item.artifact_id);
                body.append('items[' + index + '][role]', item.role);
            });
        }

        return fetch(url, {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (response) { return response.json(); })
            .then(function (payload) {
                if (!payload.ok) { throw new Error(payload.message || 'Không lưu được bộ ảnh tham chiếu.'); }

                return refresh(sceneId).then(function () { notify('Đã lưu bộ ảnh tham chiếu.', true); });
            });
    }

    function render() {
        grid.replaceChildren();

        state.data.options.forEach(function (option) {
            var taken = state.data.taken.indexOf(option.artifact_id) !== -1 && option.artifact_id !== state.target;
            var chosen = Object.prototype.hasOwnProperty.call(state.selected, option.artifact_id);
            var card = document.createElement('div');
            card.className = 'kf-pick-card' + (taken ? ' taken' : '') + (chosen ? ' chosen' : '');

            var image = document.createElement('img');
            image.src = option.url;
            image.alt = option.title;
            card.appendChild(image);

            if (chosen) {
                var check = document.createElement('span');
                check.className = 'kf-pick-check';
                check.textContent = '✓';
                card.appendChild(check);
            }

            var caption = document.createElement('div');
            caption.className = 'kf-pick-title';
            caption.textContent = option.title + (taken ? ' · đang dùng' : '');
            card.appendChild(caption);

            if (chosen && option.roles.length > 1) {
                var select = document.createElement('select');
                select.className = 'ctl';
                option.roles.forEach(function (role) {
                    var element = document.createElement('option');
                    element.value = role;
                    element.textContent = roleLabels[role] || role;
                    element.selected = role === state.selected[option.artifact_id];
                    select.appendChild(element);
                });
                select.addEventListener('click', function (event) { event.stopPropagation(); });
                select.addEventListener('change', function () { state.selected[option.artifact_id] = select.value; });
                card.appendChild(select);
            } else if (option.roles.length === 1) {
                var role = document.createElement('div');
                role.className = 'm';
                role.textContent = roleLabels[option.roles[0]] || option.roles[0];
                card.appendChild(role);
            }

            if (!taken) {
                card.addEventListener('click', function () {
                    if (chosen) {
                        delete state.selected[option.artifact_id];
                    } else {
                        if (state.limit === 1) { state.selected = {}; }
                        if (Object.keys(state.selected).length >= state.limit) { return; }
                        state.selected[option.artifact_id] = state.current[option.artifact_id] || option.roles[0];
                    }
                    render();
                });
            }

            grid.appendChild(card);
        });

        approve.disabled = Object.keys(state.selected).length === 0;
    }

    function open(sceneId, target) {
        var data = dataOf(sceneId);
        if (!data) { return; }

        var current = {};
        data.extras.forEach(function (item) { current[item.artifact_id] = item.role; });

        state = {
            sceneId: sceneId,
            data: data,
            target: target,
            current: current,
            limit: target ? 1 : data.room,
            selected: target ? (function () { var picked = {}; picked[target] = current[target]; return picked; })() : {}
        };

        document.getElementById('refPickerTitle').textContent = target ? 'ĐỔI ẢNH THAM CHIẾU' : 'CHỌN ẢNH THAM CHIẾU';
        document.getElementById('refPickerHint').textContent = target
            ? 'Chọn một ảnh để thay, hoặc giữ ảnh cũ và đổi vai trò.'
            : 'Chọn tối đa ' + data.room + ' ảnh. Ảnh đang dùng hiện mờ.';
        errorBox.style.display = 'none';
        render();
        $('#refPicker').modal('show');
    }

    approve.addEventListener('click', function () {
        if (state === null) { return; }

        var picked = Object.keys(state.selected).map(function (artifactId) {
            return { artifact_id: artifactId, role: state.selected[artifactId] };
        });
        var items = state.target
            ? state.data.extras.map(function (item) { return item.artifact_id === state.target ? picked[0] : item; })
            : state.data.extras.concat(picked);

        approve.disabled = true;
        save(state.sceneId, state.data.url, state.data.version, items, false)
            .then(function () { $('#refPicker').modal('hide'); })
            .catch(function (reason) {
                errorBox.textContent = reason.message;
                errorBox.style.display = '';
                approve.disabled = false;
            });
    });

    document.addEventListener('click', function (event) {
        var add = event.target.closest('.js-ref-add');
        var edit = event.target.closest('.js-ref-edit');
        var remove = event.target.closest('.js-ref-delete');
        var reset = event.target.closest('.js-ref-reset');

        if (add) {
            open(add.dataset.scene, null);
        } else if (edit) {
            open(edit.dataset.scene, edit.dataset.artifact);
        } else if (remove) {
            var data = dataOf(remove.dataset.scene);
            if (!data || !window.confirm('Bỏ ảnh này khỏi danh sách gửi?')) { return; }
            save(remove.dataset.scene, data.url, data.version, data.extras.filter(function (item) {
                return item.artifact_id !== remove.dataset.artifact;
            }), false).catch(function (reason) { notify(reason.message, false); });
        } else if (reset) {
            save(reset.dataset.scene, reset.dataset.url, parseInt(reset.dataset.version, 10), [], true)
                .catch(function (reason) { notify(reason.message, false); });
        }
    });
})();

(function () {
    var modal = document.getElementById('kfModal');

    if (modal === null) { return; }
    var error = document.getElementById('kfError');
    var body = document.getElementById('kfBody');
    var table = document.getElementById('kfSources');
    var form = document.getElementById('kfForm');
    var go = document.getElementById('kfGo');
    var space = document.getElementById('kfSpace');
    var spaceOptions = document.getElementById('kfSpaceOptions');
    var spaceBlocked = document.getElementById('kfSpaceBlocked');
    var spaceInput = document.getElementById('kfSpaceSource');
    var active = null;
    var spaceChoice = null;
    var gate = window.KeyframePreviewGate.create();
    var ticket = null;

    function lockRender() {
        go.disabled = true;
        document.getElementById('kfHash').value = '';
        spaceInput.value = '';
    }

    function spaceOption(value, text, checked, imageUrl) {
        var label = document.createElement('label');
        label.className = 'vs-c';
        label.style.cssText = 'display:flex;flex-direction:column;gap:4px;cursor:pointer;max-width:160px';

        var radio = document.createElement('input');
        radio.type = 'radio';
        radio.name = 'kfSpaceRadio';
        radio.value = value;
        radio.checked = checked;
        radio.addEventListener('change', function () {
            spaceChoice = value;

            if (value === 'none') {
                gate.cancel();
                ticket = null;
                lockRender();
                spaceBlocked.style.display = '';
            } else {
                load();
            }
        });
        label.appendChild(radio);

        if (imageUrl) {
            var image = document.createElement('img');
            image.src = imageUrl;
            image.alt = text;
            image.style.cssText = 'width:150px;height:auto;border:1px solid var(--vp-line)';
            label.appendChild(image);
        }

        var caption = document.createElement('span');
        caption.textContent = text;
        label.appendChild(caption);
        spaceOptions.appendChild(label);
    }

    function fillSpace(source) {
        spaceOptions.replaceChildren();
        spaceBlocked.style.display = spaceChoice === 'none' ? '' : 'none';

        if (!source) {
            space.style.display = 'none';
            spaceInput.value = '';
            return;
        }

        space.style.display = '';
        document.getElementById('kfSpaceName').textContent = source.name ? '«' + source.name + '»' : '';
        spaceInput.value = spaceChoice === 'none' ? '' : (source.chosen || '');

        if (source.options.length === 0 && source.chosen) {
            var locked = document.createElement('div');
            locked.className = 'm';
            locked.textContent = 'Nguồn hình học đã khoá trong bản này: ' + source.chosen;
            spaceOptions.appendChild(locked);
            return;
        }

        source.options.forEach(function (option) {
            spaceOption(
                option.artifact_id,
                option.title + ' · ' + (option.kind === 'anchor' ? 'ảnh neo' : (option.kind === 'environment' ? 'tấm nền phòng' : 'reference')) + ' · ' + option.sha
                    + (option.suggested ? ' · đã chọn ở lần trước (chưa xác nhận)' : ''),
                spaceChoice === option.artifact_id && source.chosen === option.artifact_id,
                option.url
            );
        });
        spaceOption('none', 'Chưa có ảnh phù hợp', spaceChoice === 'none', null);

        if (source.options.length === 0) {
            spaceBlocked.style.display = '';
        }
    }

    var roleLabels = {
        anchor: 'ảnh neo', source_keyframe: 'keyframe trước', identity: 'nhận dạng', environment: 'tấm nền',
        geometry: 'hình học', space_geometry: 'nguồn hình học', continuity: 'liền mạch', design_reference: 'thiết kế'
    };
    var groupLabels = { primary: 'IMAGE 1 · cố định', required: 'bắt buộc', extra: 'bổ sung' };

    function appendRow(source) {
        var tr = table.insertRow();
        var image = document.createElement('img');
        image.src = source.url;
        image.alt = source.title;
        image.className = 'kf-ref-thumb';

        tr.insertCell().textContent = source.position + 1;
        tr.insertCell().appendChild(image);
        tr.insertCell().textContent = source.title;
        tr.insertCell().textContent = roleLabels[source.role] || source.role;

        var tag = document.createElement('span');
        tag.className = 'kf-ref-tag';
        tag.textContent = groupLabels[source.group] || source.group;
        tr.insertCell().appendChild(tag);

        var sha = tr.insertCell();
        sha.className = 'vp-mono';
        sha.textContent = source.sha + ' · ' + source.state;
    }

    function fillReferences(preview) {
        var panel = preview.references;

        document.getElementById('kfChoiceVersion').value = panel ? panel.version : '';
        document.getElementById('kfRefNote').textContent = panel
            ? (panel.customized ? 'Bộ ảnh đã tuỳ chỉnh ở cột References.' : 'Gợi ý tự động.')
                + (panel.approved_differs ? ' Khác bộ ảnh của keyframe đã duyệt — chưa render.' : '')
            : 'Bộ ảnh của lượt render đã lưu.';
    }

    function fail(message) {
        error.textContent = message;
        error.style.display = '';
        body.style.display = 'none';
        go.disabled = true;
    }

    function fill(preview, accepted) {
        document.getElementById('kfPrompt').textContent = preview.prompt;
        document.getElementById('kfCost').textContent = preview.cost_note;
        document.getElementById('kfHash').value = preview.prompt_sha256;
        document.getElementById('kfAnchor').value = preview.anchor_confirm_artifact_id || '';

        fillReferences(preview);
        table.replaceChildren();
        preview.sources.forEach(appendRow);
        fillSpace(preview.space_source);

        error.style.display = 'none';
        body.style.display = '';
        go.disabled = !gate.renderable(accepted, preview);
    }

    function media(sceneId) {
        var picker = document.querySelector('[data-row="' + sceneId + '"][data-role="kf-model"]');
        var group = picker ? document.querySelector('[data-row="' + sceneId + '"][data-kf-group="' + picker.value + '"]') : null;

        return group ? {
            provider_model: picker.value,
            size: group.querySelector('[data-role="kf-size"]').value,
            quality: group.querySelector('[data-role="kf-quality"]').value
        } : null;
    }

    function load() {
        if (active === null) { return; }

        lockRender();
        form.action = active.dataset.render;

        var mine = gate.begin(active.dataset.scene, spaceChoice, window.AbortController);
        var url = active.dataset.preview;
        var chosen = media(active.dataset.scene);
        ticket = mine;

        document.getElementById('kfModel').value = chosen ? chosen.provider_model : '';
        document.getElementById('kfSize').value = chosen ? chosen.size : '';
        document.getElementById('kfQuality').value = chosen ? chosen.quality : '';

        if (mine.choice !== null) {
            url += (url.indexOf('?') === -1 ? '?' : '&') + 'space_source=' + encodeURIComponent(mine.choice);
        }

        if (chosen !== null) {
            Object.keys(chosen).forEach(function (key) {
                url += (url.indexOf('?') === -1 ? '?' : '&') + key + '=' + encodeURIComponent(chosen[key]);
            });
        }

        fetch(url, {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin',
            signal: mine.signal
        })
            .then(function (response) { return response.json(); })
            .then(function (payload) {
                if (!gate.isCurrent(mine)) { return; }

                if (!payload.ok) {
                    fail(payload.message);
                } else if (gate.accepts(mine, payload.preview)) {
                    fill(payload.preview, mine);
                }
            })
            .catch(function (reason) {
                if (gate.isCurrent(mine) && !(reason && reason.name === 'AbortError')) {
                    fail('Không đọc được bản xem trước.');
                }
            });
    }

    function syncModel(sceneId) {
        var picker = document.querySelector('[data-row="' + sceneId + '"][data-role="kf-model"]');
        var estimate = document.querySelector('[data-row="' + sceneId + '"][data-role="kf-estimate"]');
        var group = null;

        if (!picker) { return; }

        document.querySelectorAll('[data-row="' + sceneId + '"][data-kf-group]').forEach(function (candidate) {
            var on = candidate.getAttribute('data-kf-group') === picker.value;
            candidate.hidden = !on;
            candidate.querySelectorAll('select').forEach(function (select) { select.disabled = !on; });
            if (on) { group = candidate; }
        });

        if (!group || !estimate) { return; }

        var quality = group.querySelector('[data-role="kf-quality"]');
        var usd = quality.options[quality.selectedIndex].getAttribute('data-usd');
        estimate.textContent = picker.options[picker.selectedIndex].text + ' · '
            + group.querySelector('[data-role="kf-size"]').value + ' · ' + quality.value + ' · 1 ảnh — '
            + (usd ? 'ước lượng $' + parseFloat(usd).toFixed(3) : 'chưa định giá');
    }

    function syncModels() {
        document.querySelectorAll('[data-role="kf-model"]').forEach(function (picker) {
            syncModel(picker.getAttribute('data-row'));
        });
    }

    function notify(text, ok) {
        if (window.toastr) { window.toastr[ok ? 'success' : 'error'](text); }
    }

    function showResult(sceneId, text, tone) {
        var box = document.querySelector('[data-result="' + sceneId + '"]');
        if (!box) { return; }
        box.hidden = false;
        box.textContent = text;
        box.style.color = tone === 'ok' ? 'var(--vp-green)' : (tone === 'busy' ? 'var(--vp-amber-fg)' : 'var(--vp-red)');
    }

    function refreshGrid() {
        return fetch(window.location.href, { credentials: 'same-origin', headers: { 'Accept': 'text/html' } })
            .then(function (response) { return response.text(); })
            .then(function (html) {
                var fresh = new DOMParser().parseFromString(html, 'text/html').getElementById('storyboardGrid');
                var current = document.getElementById('storyboardGrid');
                if (fresh && current) { current.replaceWith(fresh); }
                syncModels();
            });
    }

    function send(url, data, sceneId, busy) {
        showResult(sceneId, busy + ' Trang không tải lại, kết quả sẽ hiện ở đây.', 'busy');

        return fetch(url, {
            method: 'POST',
            body: data,
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (response) {
                return response.json().then(function (payload) { return payload; });
            })
            .then(function (payload) {
                var ok = payload.ok === true;
                var errors = payload.errors ? Object.values(payload.errors).flat().join(' ') : '';
                var text = errors || payload.message || (ok ? 'Xong.' : 'Không thực hiện được.');

                return refreshGrid().then(function () {
                    showResult(sceneId, text, ok ? 'ok' : 'error');
                    notify(text, ok);
                });
            })
            .catch(function () {
                var text = 'Mất kết nối hoặc không đọc được kết quả — chưa xác định. Tải lại trang để xem trạng thái.';
                showResult(sceneId, text, 'error');
                notify(text, false);
            });
    }

    document.addEventListener('change', function (event) {
        var owner = event.target.closest('[data-role="kf-model"], [data-kf-group]');
        if (owner) { syncModel(owner.getAttribute('data-row')); }
    });

    document.addEventListener('click', function (event) {
        var button = event.target.closest('.js-kf');
        if (!button) { return; }

        active = button;
        spaceChoice = null;
        body.style.display = 'none';
        error.style.display = 'none';
        $('#kfModal').modal('show');
        load();
    });

    document.addEventListener('submit', function (event) {
        var action = event.target.closest('form.js-kf-action');
        if (!action) { return; }

        event.preventDefault();
        action.querySelectorAll('button').forEach(function (element) {
            element.disabled = true;
            element.textContent = action.dataset.busy;
        });
        send(action.action, new FormData(action), action.dataset.scene, action.dataset.busy);
    });

    $('#kfModal').on('hidden.bs.modal', function () {
        gate.cancel();
        ticket = null;
        active = null;
        lockRender();
    });

    form.addEventListener('submit', function (event) {
        event.preventDefault();

        if (ticket === null || !gate.isCurrent(ticket) || go.disabled || active === null) { return; }

        var sceneId = active.dataset.scene;
        var data = new FormData(form);
        var trigger = active;

        trigger.disabled = true;
        trigger.textContent = 'Đang render…';
        $('#kfModal').modal('hide');
        send(form.action, data, sceneId, 'Đang render keyframe…');
    });

    syncModels();
})();
</script>
@endsection
