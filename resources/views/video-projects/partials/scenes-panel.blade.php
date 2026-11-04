@php
    $shown = $step['shown'];
    $check = $shown['check'] ?? null;
    $plan = $shown['plan'] ?? null;
    $seconds = static fn (?int $ms): string => $ms === null ? '—' : rtrim(rtrim(number_format($ms / 1000, 2, '.', ''), '0'), '.');
    $evidence = static fn (array $item): string => $item['path'].(array_key_exists('quote', $item) ? ': "'.$item['quote'].'"' : ' = '.json_encode($item['value'], JSON_UNESCAPED_UNICODE));
    $detached = ['being_made' => 'đang làm', 'awaiting_install' => 'chờ lắp', 'removed' => 'đã tháo'];
    $where = ['on_motorcycle' => 'trên xe', 'off_motorcycle' => 'ngoài xe'];
    $listed = static fn (?array $items, \Closure $label): string => $items === null ? 'chưa xác lập' : ($items === [] ? 'không có' : implode(', ', array_map($label, $items)));
    $continuity = static fn (array $c): array => [
        'Vị trí' => $c['placement'] ?? 'chưa xác lập',
        'Bộ phận rời' => $listed($c['detached_parts'], static fn (array $item): string => $item['part'].' ('.$detached[$item['status']].')'),
        'Đèn bật' => $listed($c['lights_on'], static fn (string $id): string => $id),
        'Bề mặt' => $c['surface_condition'] ?? 'chưa xác lập',
        'Bảo hộ' => $c['protection'] === [] ? '—' : implode(' · ', array_map(static fn (array $entry): string => $entry['character'].': '.$listed($entry['items'], static fn (string $item): string => $item), $c['protection'])),
        'Thời tiết' => $c['weather'] ?? 'chưa xác lập',
    ];
    $shotShown = $shots['shown'] ?? null;
    $shotCheck = $shotShown['check'] ?? null;
    $shotPlan = $shotShown['plan'] ?? null;
    $shotsByScene = collect($shotPlan['shots'] ?? [])->groupBy('scene');
    $shotResources = $shotShown['resources'] ?? [];
    $readinessLabels = ['ready' => ['ok', 'ĐỦ TÀI NGUYÊN'], 'waiting_background' => ['amber', 'CHỜ NỀN'], 'waiting_reference' => ['amber', 'CHỜ ẢNH THAM CHIẾU']];
    $scaleLabels = ['extreme_wide' => 'Extreme wide', 'wide' => 'Wide', 'medium' => 'Medium', 'close' => 'Close', 'macro' => 'Macro'];
    $presentationLabels = ['installed' => 'đã lắp', 'being_made' => 'đang làm', 'awaiting_install' => 'chờ lắp', 'removed' => 'đã tháo', 'drawing' => 'bản vẽ', 'model' => 'mô hình'];
    $shotNotes = static fn (string $shotId, ?array $check): array => $check === null ? [] : array_values(array_filter([...$check['issues'], ...$check['warnings']], static fn (string $line): bool => str_contains($line, $shotId)));
@endphp

<div class="container-fluid vp" id="scenesPage">

    <div class="vp-crumb">
        <a href="{{ route('video-projects.index') }}">Video Projects</a>
        <span class="sep">/</span>
        <a href="{{ route('video-projects.anchor', $id) }}">Kịch bản</a>
        <span class="sep">·</span>
        <a href="{{ route('video-projects.locations', $id) }}">Locations & Sets</a>
        <span class="sep">·</span>
        <b>Scenes</b>
        <span class="grow"></span>
        @if($plan !== null)
            <span class="m">{{ count($plan['scenes']) }} scene · {{ $seconds($plan['total_ms']) }} s</span>
        @endif
    </div>

    <div class="vp-panel" id="scenesText" data-ajax-part>
        <div class="va-head">
            <span class="n">1</span>
            <b>SCENE VÀ CONTINUITY</b>
            <em>Phase 6 — gom các beat đã duyệt thành scene; thời gian, nhân vật, nền và trạng thái xe do code tính từ nguồn đã ghim</em>
            <span class="grow"></span>
            @if($step['running'])
                <button class="vp-btn sm" disabled>Đang chia…</button>
            @elseif($step['pending'])
                @include('video-projects.partials.task-pending', ['reset' => route('video-projects.scenes-reset', $id)])
            @elseif($step['stuck'])
                <form method="POST" action="{{ route('video-projects.scenes-reset', $id) }}">
                    @csrf
                    <button class="vp-btn sm dg">Reset lượt bị kẹt</button>
                </form>
            @elseif($step['can_run'] || $step['can_rewrite'])
                <form method="POST" action="{{ route('video-projects.scenes-write', $id) }}" id="scenesWriteForm" onsubmit="return vpLockForm(this)">
                    @csrf
                    <input type="hidden" name="operation_id" value="{{ $step['operation_id'] }}">
                    @if($step['can_rewrite'])<input type="hidden" name="rewrite" value="1">@endif
                </form>
                <button type="button" class="vp-btn sm pri" data-toggle="modal" data-target="#confirmScenesWrite">{{ $step['can_rewrite'] ? 'Chia lại scene' : 'Tạo scene' }}</button>
                @include('modal.confirm_action', [
                    'id' => 'confirmScenesWrite',
                    'form' => 'scenesWriteForm',
                    'content' => 'Gọi '.$step['model'].' (effort '.$step['effort'].') một lần để gom các beat của kịch bản đã duyệt thành scene và mô tả continuity — tác vụ này tính tiền. Chia xong không tự duyệt và không thay bản đang dùng.',
                    'detail' => 'Tối đa '.number_format((int) $step['max_tokens'], 0, ',', '.').' token đầu ra, chờ tối đa '.$step['timeout_minutes'].' phút; trả về dạng stream, tiến độ xem được khi tải lại trang.',
                ])
            @else
                <button class="vp-btn sm" disabled title="{{ $step['run_error'] }}">Tạo scene</button>
            @endif
        </div>

        <div class="va-body">
            <div class="va-brief">
                <div class="line"><span class="k">Nguồn</span><span class="v">kịch bản đã duyệt, bộ bối cảnh Phase 5 đã duyệt chữ và trạng thái thiết kế mà bộ bối cảnh đã ghim @if($step['prompt'] !== null) &middot; {{ $step['prompt'] }} &middot; {{ $step['model'] }} (effort {{ $step['effort'] }}) @endif</span></div>
                @if($step['run_error'] !== null)
                    <div class="line"><span class="k">Chưa tạo được</span><span class="v" style="color:var(--vp-red)">{{ $step['run_error'] }}</span></div>
                @endif
                @include('video-projects.partials.task-state', ['task' => $step['task'], 'projectId' => $id])
                @include('video-projects.partials.stream-state', ['stream' => $step['stream']])
                @if($step['error'] !== null)
                    <div class="line"><span class="k">Lượt gần nhất lỗi</span><span class="v" style="color:var(--vp-red)">{{ $step['error'] }}</span></div>
                @endif
                @if($step['approved_error'] !== null)
                    <div class="line"><span class="k">Bản đã duyệt</span><span class="v" style="color:var(--vp-red)">{{ $step['approved_error'] }}</span></div>
                @endif
                @if($step['history'] !== [])
                    <div class="line"><span class="k">Các lượt</span><span class="v">
                        @foreach($step['history'] as $row)
                            <a href="{{ route('video-projects.scene', ['id' => $id, 'stage' => $row['id']]) }}">rev {{ $row['revision'] }}</a>
                            {{ $row['status'] }}{{ $row['verdict'] ? ' · '.$row['verdict'] : '' }}{{ $row['model_status'] ? ' · '.$row['model_status'] : '' }}{{ $row['revision_of'] !== null ? ' · sửa tay từ rev '.$row['revision_of'] : '' }}{{ $row['approved'] ? ' · đã duyệt' : '' }}{{ $row['stale'] === true ? ' · cần cập nhật' : '' }}@if(! $loop->last) &middot; @endif
                        @endforeach
                    </span></div>
                @endif
                <div class="line"><span class="k">Shot</span><span class="v" style="color:var(--vp-amber-fg)">{{ $step['shot_plan_problem'] }}</span></div>
            </div>

            @if($shown !== null)
                <div class="va-lbl">
                    BẢN REV {{ $shown['revision'] }}
                    @if($shown['approved'])<span class="va-tag ok">ĐÃ DUYỆT</span>@endif
                    @if($shown['source_problem'] !== null)<span class="va-tag amber">CẦN CẬP NHẬT</span>@endif
                    @if($check !== null)<span class="va-tag {{ $check['verdict'] === 'PASS' ? 'ok' : 'dg' }}">RULE: {{ $check['verdict'] }}</span><span class="va-tag mute">STATUS: {{ $check['status'] ?? '—' }}</span>@endif
                    @if($shown['cost_usd'] !== null)<em>{{ $shown['tokens']['in'] }}/{{ $shown['tokens']['out'] }} token · ${{ number_format($shown['cost_usd'], 3) }}</em>@endif
                </div>
                @if($shown['integrity_error'] !== null)
                    <div class="va-lbl" style="font-weight:400;color:var(--vp-red)">Không dùng được bản này: {{ $shown['integrity_error'] }}</div>
                @endif
                @if($shown['revised'] !== null)
                    <div class="va-lbl" style="font-weight:400">Bản sửa tay từ rev {{ $shown['revised']['revision'] }} (không gọi AI): {{ $shown['revised']['reason'] }}</div>
                    @foreach($shown['revised']['changes'] as $change)
                        <div class="va-lbl" style="font-weight:400;color:var(--vp-dim)">{{ $change['path'] }}: {{ $change['before'] }} → {{ $change['after'] }}</div>
                    @endforeach
                @endif
                @if($shown['source_problem'] !== null)
                    <div class="va-lbl" style="font-weight:400;color:var(--vp-amber-fg)">Nguồn đã đổi — bản này giữ lại làm lịch sử và cần cập nhật: {{ $shown['source_problem'] }}</div>
                @endif
                @if($check !== null)
                    @if($check['error'] !== null)<div class="va-lbl" style="font-weight:400;color:var(--vp-red)">{{ $check['error'] }}</div>@endif
                    @foreach(array_merge($check['schema'], $check['issues']) as $issue)
                        <div class="va-lbl" style="font-weight:400;color:var(--vp-red)">RULE: {{ $issue }}</div>
                    @endforeach
                @endif
                @if($shown['flags'] !== [])
                    <div class="va-lbl">GHI CHÚ CỦA MODEL (FLAGS — chưa được hệ thống kiểm chứng)</div>
                    @foreach($shown['flags'] as $flag)
                        <div class="va-lbl" style="font-weight:400">{{ $flag }}</div>
                    @endforeach
                @endif
                @if($shown['approvable'])
                    <form method="POST" action="{{ route('video-projects.scenes-approve', $id) }}" onsubmit="return vpLockForm(this)" style="margin-top:8px">
                        @csrf
                        <input type="hidden" name="stage_id" value="{{ $shown['id'] }}">
                        <input type="hidden" name="content_hash" value="{{ $shown['content_hash'] }}">
                        <input type="hidden" name="selection_version" value="{{ $step['selection_version'] }}">
                        <input type="hidden" name="operation_id" value="{{ $step['operation_id'] }}">
                        <button class="vp-btn sm pri">Duyệt bản chia scene này</button>
                    </form>
                @endif
            @endif
        </div>
    </div>



    @if($plan !== null)
        <div class="vs-c vs-timeline-box">
            <b>Dòng thời gian {{ $seconds($plan['total_ms']) }} s</b>
            <div class="vs-timeline">
                @foreach($plan['scenes'] as $row)
                    <span style="flex:{{ max(1, (int) $row['duration_ms']) }}" title="{{ $row['id'] }} · {{ $seconds($row['start_ms']) }}–{{ $seconds($row['end_ms']) }} s · {{ implode(', ', $row['beats']) }}">{{ $row['id'] }}</span>
                @endforeach
            </div>
        </div>

        @if($plan['conflicts'] !== [] || $plan['source_conflicts'] !== [])
            <div class="vs-c vs-timeline-box">
                <b>Xung đột</b>
                @foreach($plan['conflicts'] as $conflict)
                    <div>
                        <span class="va-tag {{ $conflict['blocking'] ? 'dg' : 'mute' }}">{{ $conflict['blocking'] ? 'CHẶN' : 'GHI CHÚ' }}</span>
                        {{ implode(', ', array_merge($conflict['scenes'], $conflict['beats'])) }} — {{ $conflict['note'] }}
                        <span style="color:var(--vp-dim)">({{ implode(' / ', array_map($evidence, $conflict['evidence'])) }})</span>
                    </div>
                @endforeach
                @foreach($plan['source_conflicts'] as $conflict)
                    <div>
                        <span class="va-tag dg">CHẶN · BỘ BỐI CẢNH</span>
                        {{ implode(', ', array_merge($conflict['beats'], $conflict['backgrounds'])) }} — {{ $conflict['note'] }}
                    </div>
                @endforeach
            </div>
        @endif

        <div class="vs-grid vs-scenes">
            <div class="vs-h"><b>1</b> Scene</div>
            <div class="vs-h"><b>2</b> Beat · nền · trạng thái</div>
            <div class="vs-h"><b>3</b> Continuity</div>
            <div class="vs-h"><b>4</b> Shot · keyframe · clip</div>

            @foreach($plan['scenes'] as $row)
                <div class="vs-row">
                    <div class="vs-c vs-plan">
                        <span class="o">{{ $row['id'] }}</span>
                        <span class="t">{{ $row['purpose'] }}</span>
                        <span class="ph">{{ $seconds($row['start_ms']) }}–{{ $seconds($row['end_ms']) }} s</span>
                        <span class="ph">{{ $row['state'] ?? 'chưa xác lập state' }}</span>
                        <div class="d">Nơi: {{ $row['places'] === [] ? 'chưa xác lập' : implode(', ', $row['places']) }}{{ $row['backgrounds'] === [] ? '' : ' · nền '.implode(', ', $row['backgrounds']) }} · nhân vật: {{ $row['characters'] === [] ? '—' : implode(', ', $row['characters']) }}</div>
                        @if($row['set_brief'] !== null)<div class="d">Bố trí: {{ $row['set_brief'] }}</div>@endif
                        <div class="d">Vào cảnh: {{ $row['transition_in'] === null ? '—' : $row['transition_in']['kind'].' — '.$row['transition_in']['description'] }}
                            @if($row['transition_in'] !== null && $row['transition_in']['evidence'] !== [])<span style="color:var(--vp-faint)">({{ implode(' / ', array_map($evidence, $row['transition_in']['evidence'])) }})</span>@endif
                        </div>
                        @if($row['continuity_from'] !== null)<div class="d">Liền mạch từ {{ $row['continuity_from'] }}</div>@endif
                        @foreach($row['assumptions'] as $assumption)<div class="d">Giả định: {{ $assumption }}</div>@endforeach
                        @foreach(array_merge($row['conflicts'], $row['source_conflicts']) as $conflict)
                            <div class="d" style="color:{{ $conflict['blocking'] ? 'var(--vp-red)' : 'var(--vp-amber-fg)' }}">Xung đột: {{ $conflict['note'] }}</div>
                        @endforeach
                    </div>
                    <div class="vs-c">
                        @foreach($row['beat_rows'] as $beat)
                            <div class="d" style="margin-bottom:6px">
                                <b>{{ $beat['id'] }}</b> {{ $seconds($beat['start_ms']) }}–{{ $seconds($beat['end_ms']) }} s · {{ $beat['state'] ?? 'state null' }}{{ $beat['time_jump'] !== 'none' ? ' · '.$beat['time_jump'] : '' }} · {{ $beat['characters'] === [] ? 'không nhân vật' : implode(', ', $beat['characters']) }}{{ $beat['configurations'] === [] ? '' : ' · '.implode(', ', $beat['configurations']) }}{{ $beat['situation'] === null ? '' : ' · '.$beat['situation']['mode'] }}
                                <div style="color:var(--vp-dim)">{{ $beat['visual'] }}</div>
                                @foreach($beat['uses'] as $use)
                                    <div>{{ $use['background'] }} · {{ $use['location'] }}{{ $use['place'] !== null ? ' '.$use['place'] : '' }} — {{ $use['part'] }}{{ $use['support'] === [] ? '' : ' · support '.implode(', ', $use['support']) }}{{ $use['ground'] === null ? '' : ' · mặt đường '.$use['ground'] }}</div>
                                @endforeach
                                @foreach($beat['unplated'] as $entry)
                                    <div>Không nền{{ $entry['location'] !== null ? ' ('.$entry['location'].')' : '' }} — {{ $entry['part'] }}: {{ $entry['reason'] }}</div>
                                @endforeach
                                @if($beat['uses'] === [] && $beat['unplated'] === [])
                                    <div style="color:var(--vp-red)">Chưa có nền — bộ bối cảnh còn xung đột chặn beat này.</div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    <div class="vs-c">
                        @foreach(['continuity_start' => 'Đầu scene', 'continuity_end' => 'Cuối scene'] as $key => $label)
                            <div class="va-lbl">{{ $label }}</div>
                            @foreach($continuity($row[$key]) as $name => $value)
                                <div class="d">{{ $name }}: {{ $value }}</div>
                            @endforeach
                        @endforeach
                        @if(($row['work'] ?? null) !== null && $row['work'] !== [])
                            <div class="va-lbl">Công việc trên part</div>
                            @foreach($row['work'] as $item)
                                <div class="d">{{ $item['id'] }} · {{ $item['beat'] }} {{ $item['part'] }}{{ $item['with'] === [] ? '' : ' cùng '.implode(', ', $item['with']) }} · {{ $item['operation'] }}: {{ $where[$item['location_start']] }} → {{ $where[$item['location_end']] }}
                                    <span style="color:var(--vp-faint)">({{ implode(' / ', array_map($evidence, $item['evidence'])) }})</span>
                                </div>
                            @endforeach
                        @endif
                    </div>
                    <div class="vs-c">
                        @forelse($shotsByScene->get($row['id'], collect()) as $shot)
                            @php($resource = $shotResources[$shot['id']] ?? null)
                            <div class="vs-shot-item">
                                <b>{{ $shot['id'] }}</b> · {{ $scaleLabels[$shot['camera']['scale']] ?? $shot['camera']['scale'] }} · {{ $seconds($shot['tl_in_ms']) }}–{{ $seconds($shot['tl_out_ms']) }} s
                                <div class="d">{{ $shot['purpose'] }}</div>
                                <div class="vs-shot-thumb">Chưa có keyframe</div>
                                @if($resource !== null)<span class="va-tag {{ $readinessLabels[$resource['readiness']][0] ?? 'mute' }}">{{ $readinessLabels[$resource['readiness']][1] ?? $resource['readiness'] }}</span>@endif
                                <button type="button" class="vp-btn sm" data-shot-toggle="shot_{{ $shot['id'] }}">Xem storyboard</button>
                            </div>
                        @empty
                            <div style="color:var(--vp-dim)">
                                <button class="vp-btn sm" disabled title="{{ $step['shot_plan_problem'] }}">Khoá</button>
                                <div class="d">{{ $step['shot_plan_problem'] }}</div>
                            </div>
                        @endforelse
                    </div>
                </div>
                @foreach($shotsByScene->get($row['id'], collect()) as $shot)
                    @php($resource = $shotResources[$shot['id']] ?? ['readiness' => null, 'needs' => []])
                    <div class="vs-shot-detail" id="shot_{{ $shot['id'] }}" hidden>
                        <div class="vs-shot-grid">
                            <div class="vs-h"><b>1</b> Planning</div>
                            <div class="vs-h"><b>2</b> Prompt</div>
                            <div class="vs-h"><b>3</b> References</div>
                            <div class="vs-h"><b>4</b> Image</div>

                            <div class="vs-c vs-plan">
                                <span class="o">{{ $shot['id'] }}</span>
                                <span class="t">{{ $shot['purpose'] }}</span>
                                <span class="ph">{{ $seconds($shot['tl_in_ms']) }}–{{ $seconds($shot['tl_out_ms']) }} s</span>
                                <span class="ph">{{ $shot['state'] ?? 'state null' }}</span>
                                @foreach($shot['coverage_spans'] as $item)
                                    <div class="d">{{ $item['beat'] }} · {{ $item['part'] }} · {{ $seconds($item['used_duration_ms']) }} s (clip {{ $item['clip_from_ms'] }}–{{ $item['clip_to_ms'] }} ms){{ $item['result_at_ms'] !== null ? ' · kết quả ở '.$item['result_at_ms'].' ms' : '' }}</div>
                                @endforeach
                                <div class="d">Render {{ $seconds($shot['render_duration_ms']) }} s · {{ $shot['mode'] }} · giữ {{ $shot['used_start_ms'] }}–{{ $shot['used_start_ms'] + $shot['used_duration_ms'] }} ms</div>
                                <div class="d">Chủ thể: {{ $shot['camera']['target'] }}{{ $shot['camera']['target_part'] !== null ? ' '.$shot['camera']['target_part'] : '' }}</div>
                                @if($shot['parts_named'] !== [])<div class="d">Part: {{ implode('; ', array_map(static fn (array $item): string => $item['part'].' '.($item['name'] ?? '').' ('.($presentationLabels[$item['presentation']] ?? $item['presentation']).')', $shot['parts_named'])) }}</div>@endif
                                @foreach($shot['work_items'] ?? [] as $item)<div class="d">Công việc {{ $item['id'] }} · {{ $item['operation'] }}: {{ implode('; ', array_map(static fn (array $place): string => $place['part'].' '.$where[$place['start']].' → '.$where[$place['end']], $item['parts'])) }}</div>@endforeach
                                @if($shot['people'] !== [])<div class="d">Người: {{ implode('; ', array_map(static fn (array $person): string => $person['character'].' ('.$person['view'].')', $shot['people'])) }}</div>@endif
                                <div class="d">Khung đầu: {{ $shot['first_frame'] }}</div>
                                <div class="d">Hành động: {{ $shot['action'] }} → {{ $shot['result'] }}</div>
                                @if($shot['end_frame'] !== null)<div class="d">Khung cuối: {{ $shot['end_frame'] }}</div>@endif
                                @if($shot['continuity']['changes'] !== [])<div class="d">Thay đổi: {{ implode('; ', $shot['continuity']['changes']) }}</div>@endif
                                @if($shot['continuity']['carries'] !== [])<div class="d">Truyền sang shot sau: {{ implode('; ', $shot['continuity']['carries']) }}</div>@endif
                                <div class="d">Nền: {{ $shot['environment']['mode'] === 'background' ? $shot['environment']['background'] : ($shot['environment']['mode'] === 'unplated' ? 'không nền (unplated)' : 'yêu cầu nền: '.$shot['environment']['request']) }}</div>
                                @if(is_array($shot['transition_in']))<div class="d">Vào shot: {{ $shot['transition_in']['kind'] }}</div>@endif
                                @foreach($shot['cards'] as $card)<div class="d">Thẻ chữ {{ $card['type'] }}: "{{ $card['text'] }}" ({{ $card['from_ms'] }}–{{ $card['to_ms'] }} ms)</div>@endforeach
                                @if($shot['links']['match_from'] !== null || $shot['links']['motif'] !== null || $shot['links']['repeat_reason'] !== null)
                                    <div class="d">Liên kết: {{ implode(' · ', array_filter([$shot['links']['match_from'] !== null ? 'match từ '.$shot['links']['match_from'] : null, $shot['links']['motif'], $shot['links']['repeat_reason']])) }}</div>
                                @endif
                                @foreach($shot['assumptions'] as $assumption)<div class="d">Giả định: {{ $assumption }}</div>@endforeach
                                @foreach($shotNotes($shot['id'], $shotCheck) as $note)<div class="d" style="color:var(--vp-amber-fg)">{{ $note }}</div>@endforeach
                            </div>
                            <div class="vs-c vs-prompt">
                                <div class="d">{{ $shot['camera']['scale'] }} · {{ $shot['camera']['lens_mm'] }} mm · cao {{ $shot['camera']['height_m'] ?? '—' }} m · cách {{ $shot['camera']['distance_m'] ?? '—' }} m · azimuth {{ $shot['camera']['azimuth_deg'] ?? '—' }}° · elevation {{ $shot['camera']['elevation_deg'] ?? '—' }}° · {{ $shot['camera']['move'] }}{{ $shot['camera']['move_value'] !== null ? ' '.$shot['camera']['move_value'].' '.$shot['camera']['move_unit'] : '' }}</div>
                                <div class="va-lbl">Prompt ảnh (keyframe)</div>
                                <div class="body">{{ $shot['prompts']['image'] }}</div>
                                <div class="va-lbl">Prompt video (clip)</div>
                                <div class="body">{{ $shot['prompts']['video'] }}</div>
                            </div>
                            <div class="vs-c">
                                @if($resource['readiness'] !== null)<span class="va-tag {{ $readinessLabels[$resource['readiness']][0] ?? 'mute' }}">{{ $readinessLabels[$resource['readiness']][1] ?? $resource['readiness'] }}</span>@endif
                                @forelse($resource['needs'] as $need)
                                    <div class="vs-shot-ref">
                                        <b>{{ $need['kind'] }}</b>{{ $need['target'] !== null ? ' · '.$need['target'] : '' }} — {{ $need['purpose'] }}
                                        @if(($need['candidate']['artifact_id'] ?? null) !== null)
                                            <img src="{{ route('video-artifacts.show', $need['candidate']['artifact_id']) }}" alt="{{ $need['candidate']['source'] }}" loading="lazy">
                                            <div class="d">Ứng viên: {{ $need['candidate']['source'] }}</div>
                                        @elseif(($need['candidate']['source'] ?? null) !== null)
                                            <div class="d">Dùng keyframe của {{ $need['candidate']['source'] }} khi shot đó có keyframe đã duyệt</div>
                                        @endif
                                        @if($need['problem'] !== null)<div class="d" style="color:var(--vp-amber-fg)">{{ $need['problem'] }}</div>@endif
                                    </div>
                                @empty
                                    <div class="d">Không có nhu cầu tham chiếu.</div>
                                @endforelse
                            </div>
                            <div class="vs-c">
                                <div class="vs-shot-thumb tall">Chưa có keyframe</div>
                                <button class="vp-btn sm" disabled title="{{ $step['shot_plan_problem'] }}">Tạo keyframe</button>
                                <button class="vp-btn sm" disabled title="{{ $step['shot_plan_problem'] }}">Render clip</button>
                                <div class="d" style="color:var(--vp-dim)">{{ $step['shot_plan_problem'] }}</div>
                            </div>
                        </div>
                    </div>
                @endforeach
            @endforeach
        </div>
    @endif
    @if($shots !== null)
        <div class="vp-panel" id="shotsText" data-ajax-part>
            <div class="va-head">
                <span class="n">2</span>
                <b>KẾ HOẠCH SHOT</b>
                <em>Phase 7 — thiết kế cách quay từ bản chia scene đã duyệt; thời gian, nguồn, ảnh tham chiếu và prompt do code tính</em>
                <span class="grow"></span>
                @if($shots['running'])
                    <button class="vp-btn sm" disabled>Đang lập…</button>
                @elseif($shots['pending'])
                    @include('video-projects.partials.task-pending', ['reset' => route('video-projects.shots-reset', $id)])
                @elseif($shots['stuck'])
                    <form method="POST" action="{{ route('video-projects.shots-reset', $id) }}">
                        @csrf
                        <button class="vp-btn sm dg">Reset lượt bị kẹt</button>
                    </form>
                @elseif($shots['can_run'] || $shots['can_rewrite'])
                    <form method="POST" action="{{ route('video-projects.shots-write', $id) }}" id="shotsWriteForm" onsubmit="return vpLockForm(this)">
                        @csrf
                        <input type="hidden" name="operation_id" value="{{ $shots['operation_id'] }}">
                        @if($shots['can_rewrite'])<input type="hidden" name="rewrite" value="1">@endif
                    </form>
                    <button type="button" class="vp-btn sm pri" data-toggle="modal" data-target="#confirmShotsWrite">{{ $shots['can_rewrite'] ? 'Tạo lại kế hoạch shot' : 'Tạo kế hoạch shot' }}</button>
                    @include('modal.confirm_action', [
                        'id' => 'confirmShotsWrite',
                        'form' => 'shotsWriteForm',
                        'content' => 'Gọi '.$shots['model'].' (effort '.$shots['effort'].') một lần để lập shot cho toàn phim từ bản chia scene đã duyệt — tác vụ này tính tiền. Lập xong không tự duyệt, không tạo ảnh và không render.',
                        'detail' => 'Tối đa '.number_format((int) $shots['max_tokens'], 0, ',', '.').' token đầu ra, chờ tối đa '.$shots['timeout_minutes'].' phút; trả về dạng stream, tiến độ xem được khi tải lại trang.',
                    ])
                @else
                    <button class="vp-btn sm" disabled title="{{ $shots['run_error'] }}">Tạo kế hoạch shot</button>
                @endif
            </div>

            <div class="va-body">
                <div class="va-brief">
                    <div class="line"><span class="k">Nguồn</span><span class="v">bản chia scene đã duyệt và các nguồn nó ghim (kịch bản, bộ bối cảnh, thiết kế), model sheet, capability render trong registry @if($shots['prompt'] !== null) &middot; {{ $shots['prompt'] }} &middot; {{ $shots['model'] }} (effort {{ $shots['effort'] }}) @endif</span></div>
                    @if($shots['run_error'] !== null)
                        <div class="line"><span class="k">Chưa tạo được</span><span class="v" style="color:var(--vp-red)">{{ $shots['run_error'] }}</span></div>
                    @endif
                    @include('video-projects.partials.task-state', ['task' => $shots['task'], 'projectId' => $id])
                    @include('video-projects.partials.stream-state', ['stream' => $shots['stream']])
                    @if($shots['error'] !== null)
                        <div class="line"><span class="k">Lượt gần nhất lỗi</span><span class="v" style="color:var(--vp-red)">{{ $shots['error'] }}</span></div>
                    @endif
                    @if($shots['approved_error'] !== null)
                        <div class="line"><span class="k">Bản đã duyệt</span><span class="v" style="color:var(--vp-red)">{{ $shots['approved_error'] }}</span></div>
                    @endif
                    @if($shots['history'] !== [])
                        <div class="line"><span class="k">Các lượt</span><span class="v">
                            @foreach($shots['history'] as $row)
                                <a href="{{ route('video-projects.scene', ['id' => $id, 'shots' => $row['id']]) }}">rev {{ $row['revision'] }}</a>
                                {{ $row['status'] }}{{ $row['verdict'] ? ' · '.$row['verdict'] : '' }}{{ $row['model_status'] ? ' · '.$row['model_status'] : '' }}{{ $row['revision_of'] !== null ? ' · sửa tay từ rev '.$row['revision_of'] : '' }}{{ $row['approved'] ? ' · đã duyệt' : '' }}{{ $row['stale'] === true ? ' · cần cập nhật' : '' }}{{ $row['cost_usd'] !== null ? ' · $'.number_format($row['cost_usd'], 3) : '' }}@if(! $loop->last) &middot; @endif
                            @endforeach
                        </span></div>
                    @endif
                    <div class="line"><span class="k">Chi phí render</span><span class="v" style="color:var(--vp-dim)">{{ $shots['cost_note'] }}</span></div>
                </div>

                @if($shotShown !== null)
                    <div class="va-lbl">
                        BẢN REV {{ $shotShown['revision'] }}
                        @if($shotShown['approved'])<span class="va-tag ok">ĐÃ DUYỆT</span>@endif
                        @if($shotShown['source_problem'] !== null)<span class="va-tag amber">CẦN CẬP NHẬT</span>@endif
                        @if($shotCheck !== null)<span class="va-tag {{ $shotCheck['verdict'] === 'PASS' ? 'ok' : 'dg' }}">RULE: {{ $shotCheck['verdict'] }}</span><span class="va-tag mute">STATUS: {{ $shotCheck['status'] ?? '—' }}</span>@endif
                        @if($shotPlan !== null)<span class="va-tag mute">{{ count($shotPlan['shots']) }} SHOT</span>@endif
                        @foreach($shotShown['readiness'] as $state => $count)<span class="va-tag {{ $readinessLabels[$state][0] ?? 'mute' }}">{{ $count }} {{ $readinessLabels[$state][1] ?? $state }}</span>@endforeach
                        @if($shotShown['cost_usd'] !== null)<em>{{ $shotShown['tokens']['in'] }}/{{ $shotShown['tokens']['out'] }} token · ${{ number_format($shotShown['cost_usd'], 3) }}</em>@endif
                    </div>
                    @if($shotShown['integrity_error'] !== null)
                        <div class="va-lbl" style="font-weight:400;color:var(--vp-red)">Không dùng được bản này: {{ $shotShown['integrity_error'] }}</div>
                    @endif
                    @if($shotShown['source_problem'] !== null)
                        <div class="va-lbl" style="font-weight:400;color:var(--vp-amber-fg)">Nguồn đã đổi — bản này giữ lại làm lịch sử và cần cập nhật: {{ $shotShown['source_problem'] }}</div>
                    @endif
                    @if($shotShown['derived_problem'] !== null)
                        <div class="va-lbl" style="font-weight:400;color:var(--vp-red)">{{ $shotShown['derived_problem'] }}</div>
                    @endif
                    @if($shotShown['revised'] !== null)
                        <div class="va-lbl" style="font-weight:400">Bản sửa tay từ rev {{ $shotShown['revised']['revision'] }} (không gọi AI): {{ $shotShown['revised']['reason'] }}</div>
                        @foreach($shotShown['revised']['changes'] as $change)
                            <div class="va-lbl" style="font-weight:400;color:var(--vp-dim)">{{ $change['path'] }}: {{ $change['before'] }} → {{ $change['after'] }}</div>
                        @endforeach
                    @endif
                    @if($shotCheck !== null)
                        @if($shotCheck['error'] !== null)<div class="va-lbl" style="font-weight:400;color:var(--vp-red)">{{ $shotCheck['error'] }}</div>@endif
                        @foreach(array_merge($shotCheck['schema'], $shotCheck['issues']) as $issue)
                            <div class="va-lbl" style="font-weight:400;color:var(--vp-red)">RULE: {{ $issue }}</div>
                        @endforeach
                        @foreach($shotCheck['warnings'] as $warning)
                            <div class="va-lbl" style="font-weight:400;color:var(--vp-amber-fg)">WARN: {{ $warning }}</div>
                        @endforeach
                    @endif
                    @if($shotShown['flags'] !== [])
                        <div class="va-lbl">GHI CHÚ CỦA MODEL (FLAGS — chưa được hệ thống kiểm chứng)</div>
                        @foreach($shotShown['flags'] as $flag)
                            <div class="va-lbl" style="font-weight:400">{{ $flag }}</div>
                        @endforeach
                    @endif
                    @if($shotShown['approvable'])
                        <form method="POST" action="{{ route('video-projects.shots-approve', $id) }}" onsubmit="return vpLockForm(this)" style="margin-top:8px">
                            @csrf
                            <input type="hidden" name="stage_id" value="{{ $shotShown['id'] }}">
                            <input type="hidden" name="content_hash" value="{{ $shotShown['content_hash'] }}">
                            <input type="hidden" name="selection_version" value="{{ $shots['selection_version'] }}">
                            <input type="hidden" name="operation_id" value="{{ $shots['operation_id'] }}">
                            <button class="vp-btn sm pri">Duyệt kế hoạch shot</button>
                        </form>
                    @endif
                @endif
            </div>
        </div>
    @endif
</div>

<script>
document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-shot-toggle]');

    if (button === null) { return; }

    var detail = document.getElementById(button.getAttribute('data-shot-toggle'));

    if (detail !== null) { detail.hidden = ! detail.hidden; }
});
</script>
