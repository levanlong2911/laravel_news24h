@extends('layouts.base', ['title' => 'Scenes'])
@section('title', 'Scenes')

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
        <b>Scenes</b>
        <span class="grow"></span>
        <span class="m">{{ count($scenes) }} scene</span><span class="sep">·</span>
        <span class="m">Đã render <b>{{ $summary['approved'] }}</b>/{{ count($scenes) }}</span>
        @if($revision > 0)
            <span class="sep">·</span><span class="m">Bản <b>{{ $revision }}</b></span>
            <span class="sep">·</span>
            <span class="m" style="color:{{ $review['status'] === 'passed' ? 'var(--vp-green-fg)' : 'var(--vp-amber-fg)' }}">
                <b>@switch($review['status'])
                    @case('passed') Đã rà tự động @break
                    @case('needs_review') Cần kiểm tra @break
                    @default Chưa rà
                @endswitch</b>
            </span>
        @endif
        @if($records > 0)
            <span class="sep">·</span>
            <span class="m">Lập kế hoạch: <b>{{ $records }}</b> bản ghi xử lý</span>
            @if($unpriced)
                <span class="sep">·</span><span class="m">Chưa định giá</span>
            @endif
        @endif

        <form method="POST" action="{{ route('video-projects.scenes-plan', $id) }}"
              id="planScenesForm" data-modal="confirmPlanScenes"
              onsubmit="return vpLockForm(this)" hidden>
            @csrf
        </form>

        <form method="POST" action="{{ route('video-projects.scenes-plan', $id) }}"
              id="replanScenesForm" data-modal="confirmReplanScenes"
              onsubmit="return vpLockForm(this)" hidden>
            @csrf
            <input type="hidden" name="force" value="1">
        </form>

        <button type="button" class="vp-btn sm pri" data-toggle="modal"
                data-target="#confirmPlanScenes" data-busy="Đang lập kế hoạch…">Sinh danh sách scene</button>

        @if($revision > 0)
            <button type="button" class="vp-btn sm" data-toggle="modal"
                    data-target="#confirmReplanScenes" data-busy="Đang lập kế hoạch…">Sinh lại</button>
        @endif

        @include('modal.confirm_action', [
            'id' => 'confirmPlanScenes',
            'form' => 'planScenesForm',
            'content' => 'Gọi model lập danh sách scene cho dự án này — TÁC VỤ NÀY TÍNH TIỀN.',
            'detail' => 'Số scene do model quyết theo nội dung bài. Chưa có bảng giá cho model này nên chi phí ghi vào sổ là chưa định giá.',
        ])

        @include('modal.confirm_action', [
            'id' => 'confirmReplanScenes',
            'form' => 'replanScenesForm',
            'content' => 'Gọi model lập lại danh sách scene dù đầu vào chưa đổi — TÁC VỤ NÀY TÍNH TIỀN.',
            'detail' => 'Sinh một bản mới bên cạnh bản '.$revision.'; bản cũ được giữ nguyên.',
        ])
    </div>

    @php
        $reviewReasons = [
            'passed' => 'Vòng rà cuối chấp nhận bản này.',
            'patched_but_unreviewed' => 'Bản vá cuối chưa được rà lại — hết số vòng cho phép.',
            'rounds_exhausted' => 'Hết số vòng rà cho phép.',
            'requires_replan' => 'Reviewer báo phải sinh lại: lỗi không sửa được bằng cách vá scene đang có.',
            'no_progress' => 'Bản vá không đổi gì so với bản hiện tại.',
            'oscillated' => 'Bản vá đưa kế hoạch quay về một trạng thái đã qua.',
            'patch_invalid' => 'Bản vá không qua được kiểm cấu trúc — giữ bản trước đó.',
            'review_response_incoherent' => 'Câu trả lời của reviewer tự mâu thuẫn — không áp bản vá.',
            'review_call_failed' => 'Lượt gọi reviewer hỏng — kế hoạch vẫn được lưu.',
            'review_not_attempted' => 'Không dựng được yêu cầu rà — chưa gọi model.',
            'review_apply_failed' => 'Lỗi khi áp kết quả rà.',
            'planning_failed' => 'Lập kế hoạch hỏng sau khi đã rà.',
            'claim_lost' => 'Lượt lập kế hoạch bị giành mất.',
            'review_disabled' => 'Vòng rà đang tắt trong cấu hình.',
            'not_recorded' => 'Bản này có trước khi vòng rà tồn tại.',
            'unverifiable' => 'Không tìm được bản ghi chặng lập kế hoạch của bản này.',
            'unreadable' => 'Bản ghi vòng rà đọc không được.',
        ];
    @endphp

    @if($revision > 0 && $review['status'] !== 'passed')
        <div class="vs-c" style="margin-bottom:10px;color:var(--vp-amber-fg)">
            <b>Bản {{ $revision }} chưa qua vòng rà tự động</b> —
            {{ $reviewReasons[$review['reason']] ?? $review['reason'] }}
            @if($review['patched_but_unverified'])
                <div style="margin-top:4px">
                    Bản vá cuối đã qua kiểm cấu trúc nhưng <b>chưa ai đọc lại</b>. Findings dưới đây
                    thuộc bản <i>trước</i> khi vá, giữ để truy lại — không phải lỗi còn tồn tại.
                </div>
            @endif
        </div>
    @endif

    @if($review['open_findings'] !== [])
        <div class="vs-c" style="margin-bottom:10px">
            <b style="color:var(--vp-amber-fg)">Reviewer nêu {{ count($review['open_findings']) }} điểm trên bản đang hiện</b>
            <ul style="margin:6px 0 0 18px">
                @foreach($review['open_findings'] as $finding)
                    <li style="margin-bottom:4px">
                        <b>{{ $finding['scene_code'] ?? '?' }}</b>
                        <span class="m">· {{ $finding['rule'] ?? '?' }} ·
                            {{ ($finding['severity'] ?? '') === 'blocking' ? 'chặn render' : 'ghi nhận' }}</span>
                        <div>{{ $finding['problem'] ?? '' }}</div>
                        <div class="m">Đề xuất: {{ $finding['fix'] ?? '' }}</div>
                        @foreach($finding['evidence'] ?? [] as $ev)
                            <div class="m" style="margin-left:10px">
                                {{ $ev['source'] ?? '?' }}
                                @if(($ev['scene_code'] ?? '') !== ''){{ ' · '.$ev['scene_code'] }}@endif
                                · {{ $ev['field'] ?? '?' }}: “{{ $ev['quote'] ?? '' }}”
                            </div>
                        @endforeach
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if($review['rounds'] !== [])
        <details class="vs-c" style="margin-bottom:10px">
            <summary>Lịch sử {{ count($review['rounds']) }} vòng rà</summary>
            <div class="m" style="margin-top:4px">
                Đây là kết quả tại thời điểm từng vòng rà. Xem phần đánh giá hiện tại để biết
                các điểm còn cần xử lý.
            </div>
            <ul style="margin:6px 0 0 18px">
                @foreach($review['rounds'] as $round)
                    <li>
                        Vòng {{ $round['round'] ?? '?' }} ·
                        @if(isset($round['error']))
                            hỏng: {{ ($round['error']['message']['encoding'] ?? '') === 'utf8'
                                ? $round['error']['message']['text']
                                : $round['error']['class'] ?? '' }}
                        @else
                            {{ $round['verdict'] ?? '?' }} ·
                            {{ count($round['findings'] ?? []) }} điểm
                            @if(($round['patched_codes'] ?? []) !== [])
                                · đã vá: {{ implode(', ', $round['patched_codes']) }}
                            @endif
                        @endif
                        @isset($round['duration_ms'])
                            <span class="m">· {{ $round['duration_ms'] }} ms</span>
                        @endisset

                        @if(($round['findings'] ?? []) !== [])
                            <ul style="margin:4px 0 8px 18px">
                                @foreach($round['findings'] as $finding)
                                    <li style="margin-bottom:3px">
                                        <b>{{ $finding['scene_code'] ?? '?' }}</b>
                                        <span class="m">· {{ $finding['rule'] ?? '?' }} ·
                                            {{ ($finding['severity'] ?? '') === 'blocking' ? 'chặn render' : 'ghi nhận' }}</span>
                                        <div>{{ $finding['problem'] ?? '' }}</div>
                                        <div class="m">Đề xuất: {{ $finding['fix'] ?? '' }}</div>
                        @foreach($finding['evidence'] ?? [] as $ev)
                            <div class="m" style="margin-left:10px">
                                {{ $ev['source'] ?? '?' }}
                                @if(($ev['scene_code'] ?? '') !== ''){{ ' · '.$ev['scene_code'] }}@endif
                                · {{ $ev['field'] ?? '?' }}: “{{ $ev['quote'] ?? '' }}”
                            </div>
                        @endforeach
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </li>
                @endforeach
            </ul>
            @isset($review['usage']['total']['calls'])
                <div class="m" style="margin-top:6px">
                    {{ $review['usage']['total']['calls'] }} lượt gọi model ·
                    {{ ($review['usage']['total']['tokens_in'] ?? 0) + ($review['usage']['total']['tokens_out'] ?? 0) }} token
                    @if($review['usage']['total']['incomplete'] ?? false) · <b>số liệu chưa đầy đủ</b> @endif
                    · chưa định giá
                </div>
            @endisset
        </details>
    @endif

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

    <div class="vs-grid">

        <div class="vs-h"><b>1</b> Planning</div>
        <div class="vs-h"><b>2</b> Prompt</div>
        <div class="vs-h"><b>3</b> References</div>
        <div class="vs-h"><b>4</b> Image</div>

        @forelse($scenes as $s)
            <div class="vs-row">

                <div class="vs-c vs-plan">
                    <span class="o">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                    <span class="t">{{ $s['title'] }}</span>
                    <span class="ph">{{ $s['phase'] }}</span>

                    @foreach($s['milestones'] as $milestone)
                        <span class="ph">{{ $milestone }}</span>
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

                    @if($s['state_before'] || $s['scene_state'])
                        <div class="d">
                            {{ $s['state_before'] ?? '?' }} → <b>{{ $s['scene_state'] ?? '?' }}</b>
                            @if($s['end_state']) → {{ $s['end_state'] }} @endif
                        </div>
                    @endif
                </div>

                <div class="vs-c vs-prompt">
                    @if($s['image_prompt'] !== null)
                        <div class="vs-lbl">ẢNH</div>
                        <div class="body">{{ $s['image_prompt'] }}</div>
                    @endif

                    @if($s['video_prompt'])
                        <div class="vs-lbl">CLIP — bản nháp, xác nhận lại sau khi duyệt ảnh</div>
                        <div class="body">{{ $s['video_prompt'] }}</div>
                    @endif
                </div>

                @php($refs = $sources[$s['scene_id']] ?? ['slots' => [], 'blocked_reason' => null, 'blocked_code' => null])
                <div class="vs-c vs-refs">
                    <div class="vs-slots">
                        @foreach($refs['slots'] as $slot)
                            <a class="vs-slot filled {{ $slot['primary'] ? 'sent' : '' }}"
                               href="{{ $slot['url'] }}" target="_blank"
                               title="{{ $slot['role'] }} · {{ $slot['title'] }} · {{ $slot['sha'] }}">
                                <img src="{{ $slot['url'] }}" alt="{{ $slot['title'] }}">
                                <span class="cap">
                                    <b class="n">{{ $slot['position'] + 1 }} GỬI</b>
                                    {{ $slot['title'] }}
                                    @if($slot['primary'])<b>SỬA</b>@endif
                                </span>
                            </a>
                        @endforeach

                        @foreach($referenceRoles as $key => $role)
                            @if(! collect($refs['slots'])->contains('role', $key))
                                <div class="vs-slot" title="thiếu {{ $role }}">{{ $role }}</div>
                            @endif
                        @endforeach
                    </div>

                    @if($refs['blocked_reason'])
                        <div class="m" style="margin-top:6px;color:var(--vp-amber-fg)">
                            {{ $refs['blocked_reason'] }}
                            @if(str_starts_with((string) ($refs['blocked_code'] ?? ''), 'environment_'))
                                <a href="{{ route('video-projects.environment', $id) }}">Environment Library →</a>
                            @endif
                        </div>
                    @endif
                </div>

                @php($cell = $keyframes[$s['scene_id']] ?? ['approved' => null, 'candidate' => null])
                <div class="vs-c vs-img" data-scene="{{ $s['scene_id'] }}">

                    @if($cell['approved'])
                        @php($chosen = collect($cell['approved']['artifacts'])
                            ->firstWhere('id', $cell['approved']['selected_artifact_id']))
                        <div class="vs-lbl">ĐÃ DUYỆT</div>
                        @if($cell['approved']['cost_recorded_has_ledger'])
                            <div class="m">@include('video-projects.partials.cost-recorded', ['cell' => $cell['approved']])</div>
                        @endif
                        @if($chosen)
                            <a class="frame" href="{{ $chosen['url'] }}" target="_blank">
                                <img src="{{ $chosen['url'] }}" alt="">
                            </a>
                            <div class="m vp-mono">{{ $chosen['sha'] }}</div>
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
                            <form method="POST" class="vs-cand"
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
                            <form method="POST"
                                  action="{{ route('video-projects.scene-keyframe-retry', [$id, $c['id']]) }}">
                                @csrf
                                <input type="hidden" name="prompt_sha256" value="{{ $c['prompt_sha256'] }}">
                                <button class="vp-btn sm">
                                    {{ $c['status'] === 'failed' ? '↻ Thử lại' : '▸ Tiếp tục render' }}
                                </button>
                            </form>
                        @endif
                    @else
                        @if(! $cell['approved'])
                            <div class="frame">chưa render</div>
                        @endif
                        <button type="button" class="vp-btn pri sm js-kf"
                                data-preview="{{ route('video-projects.scene-keyframe-preview', [$id, $s['scene_id']]) }}"
                                data-render="{{ route('video-projects.scene-keyframe-render', [$id, $s['scene_id']]) }}">
                            {{ $cell['approved'] ? 'Tạo bản khác' : 'Xem trước → Render' }}
                        </button>
                    @endif
                </div>

            </div>
        @empty
            <div class="vs-c" style="grid-column:1/-1;color:var(--vp-dim)">
                Chưa có scene nào — số scene do model quyết theo mốc phải phủ, không cố định.
                Bấm <b>Sinh danh sách scene</b>.
            </div>
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

          <div class="vs-lbl">ẢNH NGUỒN — thứ tự này là hợp đồng gửi đi</div>
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
          <button type="submit" class="btn btn-primary" id="kfGo" disabled>Render →</button>
        </form>
      </div>
    </div>
  </div>
</div>
@endsection

@section('script')
<script src="{{ asset('assets/js/video-producer.js') }}?v={{ filemtime(public_path('assets/js/video-producer.js')) }}"></script>
<script>
(function () {
    var modal = document.getElementById('kfModal');

    if (modal === null) { return; }
    var error = document.getElementById('kfError');
    var body = document.getElementById('kfBody');
    var table = document.getElementById('kfSources');
    var form = document.getElementById('kfForm');
    var go = document.getElementById('kfGo');
    var active = null;

    function appendRow(source) {
        var tr = table.insertRow();

        [source.position, source.role, source.sha, source.state].forEach(function (value, index) {
            var td = tr.insertCell();
            td.textContent = value;
            if (index === 2) { td.className = 'vp-mono'; }
        });
    }

    function fail(message) {
        error.textContent = message;
        error.style.display = '';
        body.style.display = 'none';
        go.disabled = true;
    }

    function fill(preview) {
        document.getElementById('kfPrompt').textContent = preview.prompt;
        document.getElementById('kfCost').textContent = preview.cost_note;
        document.getElementById('kfHash').value = preview.prompt_sha256;
        document.getElementById('kfAnchor').value = preview.anchor_confirm_artifact_id || '';

        table.replaceChildren();
        preview.sources.forEach(appendRow);

        error.style.display = 'none';
        body.style.display = '';
        go.disabled = preview.blocked_reason !== null;
    }

    function load() {
        if (active === null) { return; }

        go.disabled = true;
        form.action = active.dataset.render;

        fetch(active.dataset.preview, {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin'
        })
            .then(function (response) { return response.json(); })
            .then(function (payload) {
                payload.ok ? fill(payload.preview) : fail(payload.message);
            })
            .catch(function () { fail('Không đọc được bản xem trước.'); });
    }

    document.querySelectorAll('.js-kf').forEach(function (button) {
        button.addEventListener('click', function () {
            active = button;
            body.style.display = 'none';
            error.style.display = 'none';
            $('#kfModal').modal('show');
            load();
        });
    });
})();
</script>
@endsection
