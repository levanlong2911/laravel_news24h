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

    <div class="vp-panel" id="scenesText">
        <div class="va-head">
            <span class="n">1</span>
            <b>SCENE VÀ CONTINUITY</b>
            <em>Phase 6 — gom các beat đã duyệt thành scene; thời gian, nhân vật, nền và trạng thái xe do code tính từ nguồn đã ghim</em>
            <span class="grow"></span>
            @if($step['running'])
                <button class="vp-btn sm" disabled>Đang chia…</button>
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
                            <div style="color:var(--vp-dim)">
                                <button class="vp-btn sm" disabled title="{{ $step['shot_plan_problem'] }}">Khoá</button>
                                <div class="d">{{ $step['shot_plan_problem'] }}</div>
                            </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
