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

        <form id="storyboardForm" hidden>
            @csrf
        </form>

        @php($findingErrors = collect($findings)->where('severity', 'error')->count())
        @php($findingOpen = collect($findings)->where('status', 'open')->count())
        @if($approval['shot_board'] || $approval['film_cut'])
            <span class="sep">·</span>
            @if($approval['approved'])
                <span class="m" style="color:var(--vp-green-fg)"><b>Đã duyệt storyboard</b></span>
            @else
                <span class="m" style="color:var(--vp-amber-fg)"><b>Bản nháp — chưa render được</b>
                    @if($approval['selected_revision'] > 0) (đang dùng bản {{ $approval['selected_revision'] }}) @endif
                </span>
                @if($review['status'] === 'passed' && ($filmCut === null || $filmCut['drift'] === []) && $findingErrors === 0 && $findingOpen === 0)
                    <button type="button" class="vp-btn sm" id="storyboardApprove"
                            data-action="{{ route('video-projects.storyboard-approve', $id) }}"
                            data-revision="{{ $revision }}">Duyệt storyboard</button>
                @elseif($findingErrors > 0)
                    <span class="m" style="color:var(--vp-red)">{{ $findingErrors }} lỗi kế hoạch chặn duyệt</span>
                @elseif($findingOpen > 0)
                    <span class="m" style="color:var(--vp-amber-fg)">{{ $findingOpen }} cảnh báo chờ chấp nhận</span>
                @endif
            @endif
            @if($readiness['planned'] > 0)
                <span class="sep">·</span>
                <span class="m" title="Shot có đủ ảnh nguồn hợp lệ, đã duyệt" style="color:{{ $readiness['ready'] === $readiness['planned'] ? 'var(--vp-green-fg)' : 'var(--vp-amber-fg)' }}">
                    Sẵn sàng render <b>{{ $readiness['ready'] }}</b>/{{ $readiness['planned'] }} shot
                </span>
            @endif
        @endif

        @if($storedAnswer)
            <button type="button" class="vp-btn sm" id="storyboardRecheck"
                    data-action="{{ route('video-projects.storyboard-recheck', $id) }}"
                    title="Kiểm lại câu trả lời AI thất bại đã lưu bằng code hiện tại — không gọi AI, không tính tiền">Kiểm lại câu trả lời đã lưu</button>
        @endif

        <a class="vp-btn sm" href="{{ route('video-projects.anchor', $id) }}#storyboard-panel"
           title="Nút lập storyboard nằm ngay sau phần Phân cảnh đầy đủ">{{ $revision > 0 ? 'Lập lại storyboard ở trang Kịch bản' : 'Lập storyboard ở trang Kịch bản' }}</a>
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

    @if($findings !== [])
        <div class="vs-c vs-findings" id="storyboardFindings" data-action="{{ route('video-projects.storyboard-finding-accept', $id) }}" data-revision="{{ $revision }}">
            <b>Kiểm kế hoạch bản {{ $revision }}</b> — code chỉ kiểm cấu trúc, mã, thời lượng và mâu thuẫn máy xác định được; camera và ý nghĩa liên tục vẫn cần người duyệt đánh giá.
            <ul>
                @foreach($findings as $finding)
                    <li class="vs-finding {{ $finding['severity'] }} {{ $finding['status'] }}">
                        <span class="ph">{{ $finding['severity'] === 'error' ? 'lỗi' : 'cảnh báo' }}</span>
                        <span class="ph">{{ $finding['rule'] }}</span>
                        @if($finding['shot_code'] ?? null)
                            <span class="ph">{{ $finding['shot_code'] }}</span>
                        @elseif($finding['scene_id'] ?? null)
                            <span class="ph">{{ $finding['scene_id'] }}</span>
                        @endif
                        {{ $finding['evidence'] }}
                        @if($finding['status'] === 'accepted')
                            <div class="m">Đã chấp nhận: {{ $finding['reason'] }}</div>
                        @elseif($finding['severity'] === 'warning' && ! $approval['approved'])
                            <div class="vs-finding-accept">
                                <input type="text" maxlength="500" placeholder="Lý do chấp nhận" aria-label="Lý do chấp nhận cảnh báo">
                                <button type="button" class="vp-btn sm" data-finding="{{ $finding['id'] }}">Chấp nhận</button>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if($warnings !== [] && $findings === [])
        <div class="vs-c" style="margin-bottom:10px;color:var(--vp-amber-fg)">
            <b>Cảnh báo của bản {{ $revision }}</b> — heuristic, không phải lỗi hợp đồng:
            <ul style="margin:6px 0 0 18px">
                @foreach($warnings as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if($filmCut !== null)
        @if($filmCut['legacy'])
            <div class="vs-c" style="margin-bottom:10px;color:var(--vp-red)">
                <b>Bản {{ $filmCut['revision'] }} là phim 30 giây theo định dạng cũ</b> (AI chia shot) —
                không render ảnh, không render video được. Chọn "Kế hoạch phim 30 giây · 3 đoạn" và bấm "Lập lại storyboard" để code lập lại từ phân cảnh.
            </div>
        @endif
        @if($filmCut['drift'] !== [])
            <div class="vs-c" style="margin-bottom:10px;color:var(--vp-red)">
                <b>Nguồn đã đổi kể từ lúc lập bản {{ $filmCut['revision'] }}</b> — không duyệt và không render được nữa; lập bản mới.
                <ul style="margin:6px 0 0 18px">
                    @foreach($filmCut['drift'] as $item)
                        <li>{{ $item }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
        <div class="vs-c" style="margin-bottom:10px">
            <b>Phim {{ intdiv((int) ($filmCut['preset']['total_ms'] ?? 0), 1000) }} giây</b> —
            {{ count($filmCut['segments']) }} đoạn render, {{ intdiv((int) ($filmCut['preset']['segment_ms'] ?? 0), 1000) }} s mỗi đoạn, giữ đúng thứ tự phân cảnh đã duyệt.
            @foreach($filmCut['segments'] as $segment => $items)
                <div style="margin-top:4px"><b>Đoạn {{ $segment }}</b>
                    @foreach($items as $item)
                        <div>{{ $item['scene_id'] }} · {{ $item['location_name'] }} · {{ $item['start_ms'] / 1000 }}–{{ $item['end_ms'] / 1000 }} s — {{ $item['focus'] }}</div>
                    @endforeach
                </div>
            @endforeach
            @foreach($filmCut['junctions'] as $junction)
                <div>Mối nối sau scene thứ {{ $junction['after_shot'] }}: {{ $junction['link'] }}</div>
            @endforeach
            <div style="margin-top:4px;color:var(--vp-dim)">Code lập từ phân cảnh: đoạn, thời lượng, máy và hành động lấy nguyên từ từng scene; không AI chia shot. Người duyệt kiểm sức hút và mối nối giữa các đoạn.</div>
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
                            <span class="ph" title="{{ $covered['shown'] ?? 'Phần beat shot này thể hiện' }}">{{ $covered['beat_id'] ?? '?' }}{{ ['whole' => '', 'opening' => ' · mở', 'middle' => ' · giữa', 'closing' => ' · kết'][$covered['part'] ?? ''] ?? '' }}</span>
                        @endforeach
                        @if($s['after_jump'] !== null)
                            <span class="ph" title="Trạng thái đầu shot lấy theo time jump của phân cảnh: {{ $s['after_jump']['evidence'] }}">sau time jump</span>
                        @endif
                        <span class="ph">{{ $s['camera_relation'] === 'same_camera' ? 'giữ máy' : 'máy mới' }}</span>
                        @if($s['camera_movement'] !== null && $s['camera_movement'] !== 'static')
                            <span class="ph" title="Chuyển động máy trong clip">máy {{ str_replace('_', ' ', $s['camera_movement']) }}</span>
                        @endif
                        @if($s['duration_seconds'] !== null)
                            <span class="ph" title="Thời lượng clip render">{{ $s['duration_seconds'] }}s</span>
                        @endif
                        @if($s['used_ms'] !== null)
                            <span class="ph" title="Đoạn clip dùng trong bản dựng (điểm vào–ra)">dùng {{ ($s['used_start_ms'] ?? 0) / 1000 }}–{{ (($s['used_start_ms'] ?? 0) + $s['used_ms']) / 1000 }}s</span>
                        @endif
                        @if($s['result_at_ms'] !== null)
                            <span class="ph" title="Thời điểm trong clip hành động đạt kết quả">kết quả ở {{ $s['result_at_ms'] / 1000 }}s</span>
                        @endif
                        @if($s['timeline'] !== null)
                            <span class="ph" title="Vị trí trên timeline phim">{{ $s['timeline']['start_ms'] / 1000 }}–{{ $s['timeline']['end_ms'] / 1000 }}s</span>
                        @endif
                        @if(isset($readiness['shots'][$s['scene_id']]))
                            <span class="ph" style="color:{{ $readiness['shots'][$s['scene_id']]['ready'] ? 'var(--vp-green-fg)' : 'var(--vp-amber-fg)' }}">{{ $readiness['shots'][$s['scene_id']]['ready'] ? 'đủ ảnh nguồn' : 'chưa đủ ảnh nguồn' }}</span>
                        @endif
                    @else
                        @foreach($s['beat_ids'] as $beatId)
                            <span class="ph" title="Beat của scene">{{ $beatId }}</span>
                        @endforeach
                    @endif

                    @if($s['film'] !== null)
                        <span class="ph" title="Đoạn render và giây trong đoạn">Đoạn {{ $s['film']['segment'] }} · {{ $s['film']['start_ms'] / 1000 }}–{{ $s['film']['end_ms'] / 1000 }} s</span>
                        <span class="ph" title="Giai đoạn của scene">{{ $s['film']['content_group'] }}</span>
                        <div class="m"><b>Nơi:</b> {{ $s['film']['location_name'] ?? '—' }}</div>
                        <div class="m"><b>Máy:</b> {{ $s['film']['camera'] }}</div>
                        <div class="m"><b>Tiêu điểm:</b> {{ $s['film']['focus'] }}</div>
                        <div class="m"><b>Kịch bản ({{ $s['film']['scene_id'] }}):</b> {{ $s['film']['source']['action'] }}</div>
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
                            @forelse($readiness['shots'][$s['scene_id']]['needs'] ?? $s['reference_requirements'] as $need)
                                <div class="vs-need {{ $need['status'] ?? '' }}">
                                    {{ ['design' => 'thiết kế', 'continuity' => 'liền mạch', 'identity' => 'nhận dạng', 'environment' => 'tấm nền'][$need['kind'] ?? ''] ?? '?' }} ({{ $need['purpose'] ?? '' }})
                                    @isset($need['status'])
                                        — {{ ['available' => 'có ảnh phù hợp', 'needs_selection' => 'cần chọn và duyệt', 'missing' => 'thiếu ảnh', 'waiting_source' => 'chờ ảnh của shot nguồn', 'not_applicable' => 'không cần tấm nền', 'mismatch' => 'ảnh đang gắn không đúng yêu cầu', 'needs_confirmation' => 'chờ người duyệt xác nhận'][$need['status']] ?? $need['status'] }}
                                    @endisset
                                    @if(($need['confirmed_by'] ?? null) !== null)
                                        <span class="d">· đã xác nhận bằng mắt</span>
                                    @endif
                                    @if(($need['status'] ?? null) === 'needs_confirmation')
                                        <button type="button" class="vp-btn sm" data-reference-confirm="{{ route('video-projects.scene-reference-confirm', [$id, $s['scene_id']]) }}"
                                                data-index="{{ $need['index'] }}" data-artifact="{{ $need['fitting_artifact_ids'][0] ?? '' }}">Xác nhận ảnh đúng yêu cầu</button>
                                    @endif
                                    @if(($need['misfits'] ?? []) !== [])
                                        <span class="d">· lệch: {{ implode(', ', array_map(static fn (string $field): string => ['source_shot' => 'shot nguồn', 'view' => 'góc nhìn', 'design_revision' => 'revision thiết kế'][$field] ?? $field, $need['misfits'])) }}</span>
                                    @endif
                                    @if(($need['unchecked'] ?? []) !== [])
                                        <span class="d">· code không kiểm được, cần người xác nhận bằng mắt: {{ implode(', ', array_map(static fn (string $field): string => ['state' => 'trạng thái', 'design_refs' => 'phần thiết kế'][$field] ?? $field, $need['unchecked'])) }}</span>
                                    @endif
                                    @if(($need['must_show'] ?? null) !== null)
                                        <span class="d">· cần thấy: {{ $need['must_show'] }}</span>
                                    @endif
                                    @if(($need['view'] ?? null) !== null)
                                        <span class="d">· góc: {{ $need['view'] }}</span>
                                    @endif
                                    @if(($need['state'] ?? null) !== null)
                                        <span class="d">· trạng thái: {{ $need['state'] }}</span>
                                    @endif
                                    @if(($need['design_refs'] ?? []) !== [])
                                        <span class="d">· thiết kế: {{ implode(', ', $need['design_refs']) }}</span>
                                    @endif
                                    @if(($need['source_shot'] ?? null) !== null)
                                        <span class="d">· nguồn: {{ $need['source_shot'] }}</span>
                                    @endif
                                </div>
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

                @php($refs = $sources[$s['scene_id']] ?? ['slots' => [], 'blocked_reason' => null, 'blocked_code' => null, 'references' => null, 'room' => null])
                @php($room = $refs['room'] ?? null)
                @php($cell = $keyframes[$s['scene_id']] ?? ['approved' => null, 'candidate' => null])
                @php($kfModels = $keyframeModels[0])
                @php($kfModelsError = $keyframeModels[1])
                @php($kfDefault = collect($kfModels)->firstWhere('default', true) ?? ($kfModels[0] ?? null))
                <div class="vs-c vs-refs">
                    @if($room === null)
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

                    @include('video-projects.partials.keyframe-tools')
                    @endif
                </div>

                <div class="vs-c vs-img" data-scene="{{ $s['scene_id'] }}">
                    <div class="m kf-result" data-result="{{ $s['scene_id'] }}" hidden></div>

                    @if($room !== null)
                        <div class="vs-lbl">ẢNH ĐỊA ĐIỂM ĐÃ DUYỆT</div>
                        @if($room['url'] !== null)
                            <a class="frame" href="{{ $room['url'] }}" target="_blank">
                                <img src="{{ $room['url'] }}" alt="{{ $room['label'] }}">
                            </a>
                            <div class="m">{{ $room['label'] }} · gửi vào Đoạn {{ $room['segment'] }}</div>
                        @else
                            <div class="m" style="color:var(--vp-amber-fg)">{{ strtoupper((string) $s['screenplay_scene_code']) }}: {{ $refs['blocked_reason'] }}</div>
                        @endif
                        <a class="m" href="{{ $room['environment_url'] }}">Mở trong Environment Library →</a>

                        <details class="kf-optional" @if($cell['candidate'] || $room['keyframe_chosen'] || $room['keyframe_stale']) open @endif>
                            <summary>Keyframe tuỳ chọn · {{ $room['keyframe_chosen'] ? 'đang gửi bổ sung vào Đoạn '.$room['segment'] : ($room['keyframe_stale'] ? 'lựa chọn hết hiệu lực' : 'không gửi') }}</summary>
                            @if($room['keyframe_stale'])
                                <div class="m" style="color:var(--vp-amber-fg)">Keyframe đã chọn gửi bổ sung không còn khớp keyframe đang duyệt — Đoạn {{ $room['segment'] }} bị chặn tới khi chọn lại hoặc bỏ lựa chọn.</div>
                            @endif
                            @if($room['url'] !== null)
                                @include('video-projects.partials.keyframe-tools')
                            @endif
                            @include('video-projects.partials.keyframe-result')
                            @if($cell['approved'] && ! $room['keyframe_chosen'])
                                <form method="POST" class="js-kf-action" data-scene="{{ $s['scene_id'] }}" data-busy="Đang lưu…" action="{{ $room['use_url'] }}">
                                    @csrf
                                    <input type="hidden" name="use" value="1">
                                    <button class="vp-btn sm">{{ $room['keyframe_stale'] ? 'Chọn keyframe đang duyệt' : 'Gửi bổ sung vào Đoạn '.$room['segment'] }}</button>
                                </form>
                            @endif
                            @if($room['keyframe_chosen'] || $room['keyframe_stale'])
                                <form method="POST" class="js-kf-action" data-scene="{{ $s['scene_id'] }}" data-busy="Đang lưu…" action="{{ $room['use_url'] }}">
                                    @csrf
                                    <input type="hidden" name="use" value="0">
                                    <button class="vp-btn sm">{{ $room['keyframe_chosen'] ? 'Thôi gửi, chỉ dùng ảnh địa điểm' : 'Bỏ lựa chọn, chỉ dùng ảnh địa điểm' }}</button>
                                </form>
                            @endif
                        </details>
                    @else
                        @include('video-projects.partials.keyframe-result')
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
    var status = document.getElementById('storyboardStatus');

    if (form === null || status === null) { return; }

    function show(text, color) {
        status.style.display = '';
        status.style.color = color;
        status.textContent = text;
    }

    function notify(text, ok) {
        if (window.toastr) { window.toastr[ok ? 'success' : 'error'](text); }
    }

    var postJson = function (url, fields, done) {
        var body = new FormData(form);
        Object.keys(fields).forEach(function (key) { body.append(key, fields[key]); });

        return fetch(url, {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (response) { return response.json(); }).then(done);
    };

    var recheckButton = document.getElementById('storyboardRecheck');

    if (recheckButton !== null) {
        recheckButton.addEventListener('click', function () {
            recheckButton.disabled = true;
            postJson(recheckButton.dataset.action, {}, function (payload) {
                var text = payload.message || 'Không kiểm lại được câu trả lời đã lưu.';
                notify(text, payload.ok === true);

                if (payload.ok === true) {
                    window.location.reload();

                    return;
                }

                recheckButton.disabled = false;
                show(text, 'var(--vp-red)');
            }).catch(function () {
                recheckButton.disabled = false;
                show('Không đọc được kết quả kiểm lại — tải lại trang để xem trạng thái.', 'var(--vp-amber-fg)');
            });
        });
    }

    document.querySelectorAll('button[data-reference-confirm]').forEach(function (confirmButton) {
        confirmButton.addEventListener('click', function () {
            confirmButton.disabled = true;
            postJson(confirmButton.dataset.referenceConfirm, {
                index: confirmButton.dataset.index,
                artifact_id: confirmButton.dataset.artifact
            }, function (payload) {
                notify(payload.message || 'Không ghi được xác nhận.', payload.ok === true);

                if (payload.ok === true) {
                    window.location.reload();

                    return;
                }

                confirmButton.disabled = false;
            }).catch(function () {
                confirmButton.disabled = false;
                show('Không đọc được kết quả xác nhận — tải lại trang để xem trạng thái.', 'var(--vp-amber-fg)');
            });
        });
    });

    var findingsPanel = document.getElementById('storyboardFindings');

    if (findingsPanel !== null) {
        findingsPanel.querySelectorAll('button[data-finding]').forEach(function (button) {
            button.addEventListener('click', function () {
                var input = button.parentNode.querySelector('input');
                button.disabled = true;
                postJson(findingsPanel.dataset.action, {
                    revision: findingsPanel.dataset.revision,
                    finding_id: button.dataset.finding,
                    reason: input.value
                }, function (payload) {
                    notify(payload.message || 'Không ghi được quyết định.', payload.ok === true);

                    if (payload.ok === true) {
                        window.location.reload();

                        return;
                    }

                    button.disabled = false;
                }).catch(function () {
                    button.disabled = false;
                    show('Không đọc được kết quả — tải lại trang để xem trạng thái.', 'var(--vp-amber-fg)');
                });
            });
        });
    }

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
    var active = null;
    var gate = window.KeyframePreviewGate.create();
    var ticket = null;

    function lockRender() {
        go.disabled = true;
        document.getElementById('kfHash').value = '';
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

        var mine = gate.begin(active.dataset.scene, window.AbortController);
        var url = active.dataset.preview;
        var chosen = media(active.dataset.scene);
        ticket = mine;

        document.getElementById('kfModel').value = chosen ? chosen.provider_model : '';
        document.getElementById('kfSize').value = chosen ? chosen.size : '';
        document.getElementById('kfQuality').value = chosen ? chosen.quality : '';

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
