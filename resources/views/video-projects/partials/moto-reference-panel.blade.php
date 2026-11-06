@php
    $ref = $motoReference;
    $preview = $ref['preview'];
    $plan = $preview['plan'] ?? null;
    $stage = $ref['stage'];
    $references = $ref['references'];
    $labels = $ref['labels'];
    $pixels = static fn (string $size): string => str_replace('x', ' × ', $size);
    $oneImage = static fn (array $layout): bool => (int) ($layout['rows'] ?? 0) * (int) ($layout['columns'] ?? 0) === 1;
    $tag = static fn (string $status): string => '<span class="va-tag '.($labels[$status][0] ?? 'mute').'">'.e($labels[$status][1] ?? $status).'</span>';
    $regimeLabel = ['grey' => 'NGUỒN XÁM — chỉ hình học', 'colour' => 'NGUỒN MÀU — có lớp hoàn thiện'];
    $roleLabel = ['subject' => 'chính', 'context' => 'ngữ cảnh'];
    $needsByShot = collect($plan['needs'] ?? [])->groupBy('shot_id');
    $bindings = collect($plan['bindings'] ?? [])->keyBy('need_id');
    $factText = static fn (array $reference, array $ids): string => implode(' | ', array_map(static fn (string $id): string => $id.': '.(collect($reference['facts'])->firstWhere('id', $id)['value'] ?? '?'), $ids));
    $shotPrompts = $ref['shot_prompts'];
    $review = $ref['review'];
@endphp

<div class="container-fluid vp" id="motoReferencePage">

    <div class="vp-crumb">
        <a href="{{ route('video-projects.index') }}">Video Projects</a>
        <span class="sep">/</span>
        <a href="{{ route('video-projects.anchor', $id) }}">Kịch bản</a>
        <span class="sep">·</span>
        <a href="{{ route('video-projects.scene', $id) }}">Scenes</a>
        <span class="sep">·</span>
        <b>Reference</b>
        <span class="grow"></span>
        @if($plan !== null)
            <span class="m">{{ count($plan['references']) }} ảnh cần tạo · {{ count($plan['needs']) }} nhu cầu</span>
        @endif
    </div>

    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="vp-panel va-anchor">
        <div class="va-head">
            <b>REFERENCE · PARTS DETAIL</b>
            <em>từ shot đã duyệt — chỉ tạo ảnh còn thiếu, dùng lại nguồn đã duyệt</em>
            <span class="grow"></span>
            <span class="va-tag ok">{{ $ref['counts']['done'] }} đã có ảnh phù hợp</span>
            <span class="va-tag amber">{{ $ref['counts']['pending'] }} đang chờ xử lý/duyệt</span>
            <span class="va-tag dg">{{ $ref['counts']['missing'] }} còn thiếu cần tạo</span>
        </div>
        <div class="va-body">
            <div class="va-brief">
                @if($ref['error'] !== null)
                    <div class="line"><span class="k">Chưa lập được</span><span class="v" style="color:var(--vp-red)">{{ $ref['error'] }}</span></div>
                @else
                    <div class="line"><span class="k">Nguồn</span><span class="v">kế hoạch shot {{ substr($preview['source']['shot_stage_id'], 0, 8) }} · thiết kế {{ substr($preview['source']['design_stage_id'], 0, 8) }} · model sheet {{ substr($preview['source']['model_sheet_artifact_id'], 0, 8) }} ({{ substr($preview['source']['model_sheet_sha256'], 0, 12) }})</span></div>
                    <div class="line">
                        <span class="k">Kế hoạch</span>
                        <span class="v">
                            @if($stage !== null)
                                đã lập — rev {{ $stage->planning_revision }} · {{ substr((string) $stage->id, 0, 8) }} · {{ $plan['version'] }} / {{ $plan['schema'] }}
                            @else
                                chưa lập cho nguồn hiện hành
                            @endif
                        </span>
                    </div>
                    @if($stage === null)
                        <form method="POST" action="{{ route('video-projects.parts-detail', [$id, 'plan']) }}" onsubmit="return vpLockForm(this)">
                            @csrf
                            <input type="hidden" name="hash" value="{{ $preview['hash'] }}">
                            <button class="vp-btn pri sm" data-busy="Đang lập…">Lập kế hoạch Reference (không gọi AI, không render)</button>
                        </form>
                    @endif
                @endif
                @if($review['task'] !== null)
                    @include('video-projects.partials.task-state', ['task' => $review['task'], 'projectId' => $id])
                @endif
                @if($review['stream'] !== null)
                    @include('video-projects.partials.stream-state', ['stream' => $review['stream']])
                @endif
                @if($review['error'] !== null)
                    <div class="line"><span class="k">Lượt kiểm prompt gần nhất lỗi</span><span class="v" style="color:var(--vp-red)">{{ $review['error'] }}</span></div>
                @endif
                @if(($review['latest']['issues'] ?? []) !== [])
                    @foreach($review['latest']['issues'] as $issue)
                        <div class="line"><span class="v" style="color:var(--vp-red)">Lỗi kiểm: {{ $issue }}</span></div>
                    @endforeach
                @endif
            </div>
        </div>
    </div>

    @if($plan !== null)
        <div class="vp-panel va-anchor">
            <div class="va-head"><b>NHU CẦU THEO SHOT</b><em>part, trạng thái, mốc công việc, góc và ảnh đang đáp ứng</em></div>
            <div class="vr-needs">
                <div class="vr-need vr-need-head"><div>Shot · nhu cầu</div><div>Part · trạng thái · mốc</div><div>Góc</div><div>Ảnh đáp ứng</div></div>
                @foreach($needsByShot as $shotId => $needs)
                    @foreach($needs as $need)
                        @continue(! in_array($need['kind'], ['design', 'identity'], true))
                        @php
                            $binding = $bindings[$need['id']] ?? null;
                            $bound = $binding !== null && $binding['type'] === 'reference' ? ($references[$binding['source']] ?? null) : null;
                            $shared = $bound !== null && count(array_unique(array_map(static fn (string $n): string => (string) strstr($n, '#', true), $bound['needs']))) > 1;
                        @endphp
                        <div class="vr-need">
                            <div><b>{{ $shotId }}</b> · {{ $need['kind'] }}<div class="va-lbl" style="font-weight:400">{{ $need['purpose'] }}</div></div>
                            <div>
                                {{ $need['target'] ?? 'toàn bộ phần trong khung' }} · {{ $need['state'] }}
                                @if($need['placement'] !== null) · {{ $need['placement']['presentation'] }}{{ $need['placement']['location'] !== null ? ', '.$need['placement']['location'] : '' }} @endif
                                @if($need['checkpoint'] !== null)<div class="va-lbl" style="font-weight:400">Mốc: {{ $need['checkpoint']['text'] }}</div>@endif
                            </div>
                            <div>az {{ $need['camera']['azimuth_deg'] ?? 'chưa xác định' }} · el {{ $need['camera']['elevation_deg'] ?? '—' }} · {{ $need['camera']['scale'] ?? '' }}</div>
                            <div>
                                @if($binding === null)
                                    <span class="va-tag dg">CHƯA LIÊN KẾT</span>
                                @elseif($binding['type'] === 'model_sheet')
                                    <span class="va-tag ok">MODEL SHEET {{ $binding['source'] }}</span> <span class="va-lbl" style="font-weight:400">{{ $binding['rule'] }}</span>
                                @elseif($binding['type'] === 'model_sheet_candidate')
                                    <span class="va-tag amber">CHỜ XÁC NHẬN {{ implode(' / ', $binding['candidates']) }}</span>
                                    @if($stage !== null)
                                        @foreach($binding['candidates'] as $view)
                                            <form method="POST" action="{{ route('video-projects.parts-detail', [$id, 'confirm']) }}" style="display:inline" onsubmit="return vpLockForm(this)">
                                                @csrf
                                                <input type="hidden" name="hash" value="{{ $preview['hash'] }}"><input type="hidden" name="need_id" value="{{ $need['id'] }}"><input type="hidden" name="view" value="{{ $view }}"><input type="hidden" name="decision" value="use">
                                                <button class="vp-btn sm ok" data-busy="…">Dùng {{ $view }}</button>
                                            </form>
                                        @endforeach
                                        <form method="POST" action="{{ route('video-projects.parts-detail', [$id, 'confirm']) }}" style="display:inline" onsubmit="return vpLockForm(this)">
                                            @csrf
                                            <input type="hidden" name="hash" value="{{ $preview['hash'] }}"><input type="hidden" name="need_id" value="{{ $need['id'] }}"><input type="hidden" name="view" value="{{ $binding['candidates'][0] }}"><input type="hidden" name="decision" value="refuse">
                                            <button class="vp-btn sm dg" data-busy="…">Không dùng — tạo ảnh riêng</button>
                                        </form>
                                    @endif
                                @elseif($bound !== null)
                                    <span class="va-lbl" style="font-weight:400">{{ $bound['key'] }}</span> {!! $tag($ref['state'][$bound['id']]['status']) !!}
                                    @if($binding['rule'] === 'identity_scope')<div class="va-lbl" style="font-weight:400">nhận diện vùng đang quay bằng ảnh chi tiết</div>@endif
                                    @if($shared && $stage !== null)
                                        <form method="POST" action="{{ route('video-projects.parts-detail', [$id, 'split']) }}" style="display:inline" onsubmit="return vpLockForm(this)">
                                            @csrf
                                            <input type="hidden" name="hash" value="{{ $preview['hash'] }}"><input type="hidden" name="need_id" value="{{ $need['id'] }}">
                                            <button class="vp-btn sm" data-busy="…">Tách ảnh riêng</button>
                                        </form>
                                    @endif
                                @else
                                    <span class="va-tag dg">{{ $binding['rule'] }}</span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                @endforeach
            </div>
        </div>

        @if($ref['checks'] !== [])
            <div class="vp-panel va-anchor">
                <div class="va-head"><b>CHỜ KIỂM</b><em>ô chưa render được — thiếu dữ kiện, AI báo thiếu hoặc bề mặt chưa xác lập</em></div>
                <div class="va-body">
                    @foreach($ref['checks'] as $check)
                        @php
                            $reference = $check['reference'];
                            $formId = 'refRecheck'.$reference['id'];
                        @endphp
                        <div class="va-brief">
                            <div class="line"><span class="k">{{ $reference['key'] }}</span><span class="v">{!! $tag($check['state']['status']) !!}</span></div>
                            @foreach($reference['blocked'] as $line)
                                <div class="line"><span class="v" style="color:var(--vp-red)">{{ $line }}</span></div>
                            @endforeach
                            @if(($check['state']['review']['gaps'] ?? []) !== [])
                                <form method="POST" action="{{ route('video-projects.parts-detail', [$id, 'answer']) }}" onsubmit="return vpLockForm(this)">
                                    @csrf
                                    <input type="hidden" name="hash" value="{{ $preview['hash'] }}"><input type="hidden" name="reference_id" value="{{ $reference['id'] }}">
                                    @foreach($check['state']['review']['gaps'] as $index => $gap)
                                        <div class="line"><span class="k">Thiếu</span><span class="v">{{ $gap['missing'] }} — {{ $gap['reason'] }}</span></div>
                                        <input type="hidden" name="decisions[{{ $index }}][question]" value="{{ $gap['missing'] }}">
                                        <textarea class="va-ta" name="decisions[{{ $index }}][answer]" rows="2" maxlength="1000" required placeholder="Quyết định của bạn cho điểm này"></textarea>
                                    @endforeach
                                    <button class="vp-btn sm" data-busy="Đang lưu…">Lưu quyết định (không gọi AI)</button>
                                </form>
                            @endif
                            @include('video-projects.partials.moto-reference-decisions', ['reference' => $reference, 'editable' => in_array($check['state']['status'], \App\Services\Video\PartsDetailService::ANSWERABLE, true)])
                            @if($check['estimate'] !== null)
                                <form method="POST" action="{{ route('video-projects.parts-detail', [$id, 'review']) }}" id="{{ $formId }}" data-modal="confirm{{ $formId }}" onsubmit="return vpLockForm(this)" hidden>
                                    @csrf
                                    <input type="hidden" name="plan_id" value="{{ $stage->id }}"><input type="hidden" name="cells[]" value="{{ $reference['id'] }}">
                                    <input type="hidden" name="operation_id" value="{{ \Illuminate\Support\Str::uuid() }}"><input type="hidden" name="retry" value="1"><input type="hidden" name="confirm_cost" value="1">
                                </form>
                                <button type="button" class="vp-btn sm" data-toggle="modal" data-target="#confirm{{ $formId }}" data-busy="Đang kiểm…">Kiểm lại ô này</button>
                                @include('modal.confirm_action', ['id' => 'confirm'.$formId, 'form' => $formId, 'content' => 'Gọi '.$check['estimate']['model'].' kiểm lại prompt của 1 ô — tác vụ này tính tiền ('.$check['estimate']['text'].').'])
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="vp-panel va-anchor">
            <div class="va-head"><b>MASTER REFERENCE</b><em>lưới và size theo số ảnh của master, ô dư để trống — một master chỉ một bộ ảnh đầu vào</em></div>
            <div class="va-rows">
                <div class="va-row va-row-head"><div>Master</div><div>Prompt</div><div>Render</div><div>Candidate images</div></div>

                @forelse($ref['sheets'] as $sheet)
                    @php
                        $renderId = 'refRender'.$sheet['id'];
                        $reviewId = 'refReview'.$sheet['id'];
                    @endphp
                    <div class="va-row">
                        <div class="va-row-who">
                            <b>{{ $sheet['id'] }}</b>
                            <span class="va-tag {{ $sheet['regime'] === 'grey' ? 'mute' : 'blue' }}">{{ $regimeLabel[$sheet['regime']] }}</span>
                            @if($sheet['medium'] === 'digital')<span class="va-tag blue">MÔ HÌNH SỐ</span>@endif
                            @if($oneImage($sheet['layout']))
                                <div class="va-lbl" style="font-weight:400">Một ảnh nguyên tấm {{ $pixels($sheet['layout']['size']) }}, không lưới, không cắt</div>
                            @else
                                <div class="va-lbl" style="font-weight:400">Lưới {{ $sheet['layout']['rows'] }} × {{ $sheet['layout']['columns'] }} · {{ $pixels($sheet['layout']['size']) }} · ô {{ $sheet['layout']['cell']['width'] }} × {{ $sheet['layout']['cell']['height'] }} · {{ count($sheet['cell_ids']) }} ảnh, {{ $sheet['layout']['rows'] * $sheet['layout']['columns'] - count($sheet['cell_ids']) }} ô trống</div>
                            @endif
                            @foreach($sheet['cells'] as $index => $cell)
                                <div class="vr-cell">
                                    @if(! $oneImage($sheet['layout']))
                                        <div class="va-lbl">Ô {{ $index + 1 }} · hàng {{ intdiv($index, $sheet['layout']['columns']) + 1 }}, cột {{ $index % $sheet['layout']['columns'] + 1 }}</div>
                                    @endif
                                    <div class="va-lbl" style="font-weight:400">{{ $cell['reference']['key'] }}</div>
                                    <div class="va-lbl" style="font-weight:400">{{ implode(' · ', array_map(static fn (array $p): string => $p['part'].' ('.$roleLabel[$p['role']].', '.$p['presentation'].')', $cell['reference']['parts'])) }}</div>
                                    <div class="va-lbl" style="font-weight:400">Shot: {{ implode(', ', $cell['reference']['shots']) }}</div>
                                    {!! $tag($cell['state']['status']) !!}
                                    @if($cell['state']['status'] === 'prompt_failed')
                                        @foreach($cell['state']['review']['issues'] ?? [] as $issue)
                                            <div class="va-lbl" style="font-weight:400;color:var(--vp-red)">{{ $issue }}</div>
                                        @endforeach
                                    @endif
                                </div>
                            @endforeach
                        </div>

                        <div class="va-row-prompt">
                            @if($sheet['prompt'] !== null)
                                <div class="va-lbl">PROMPT GỬI ĐI <em>(code ghép, dòng ô đã qua kiểm và đã xác nhận)</em></div>
                                <textarea class="va-ta" readonly>{{ $sheet['prompt'] }}</textarea>
                                <div class="va-count"><span>{{ mb_strlen($sheet['prompt']) }}</span> ký tự</div>
                            @elseif($sheet['reviewed_prompt'] !== null)
                                <div class="va-lbl">PROMPT CHỜ XÁC NHẬN <em>(AI đã viết lại — code không xác minh được nghĩa; đọc phần thay đổi bên dưới rồi xác nhận)</em></div>
                                <textarea class="va-ta" readonly>{{ $sheet['reviewed_prompt'] }}</textarea>
                                <div class="va-count"><span>{{ mb_strlen($sheet['reviewed_prompt']) }}</span> ký tự</div>
                            @else
                                <div class="va-lbl">BẢN NHÁP CODE <em>(chưa kiểm — không gửi đi)</em></div>
                                <textarea class="va-ta" readonly>{{ $sheet['draft_prompt'] }}</textarea>
                                <div class="va-count"><span>{{ mb_strlen((string) $sheet['draft_prompt']) }}</span> ký tự</div>
                            @endif
                            @if($sheet['prompt_error'] !== null)
                                <div class="va-lbl" style="font-weight:400;color:var(--vp-red)">{{ $sheet['prompt_error'] }}</div>
                            @endif
                            <div class="va-lbl" style="font-weight:400">Ảnh đầu vào: {{ $sheet['regime'] === 'grey' ? 'bản xám do code tạo từ master model sheet đã duyệt' : 'master model sheet đã duyệt' }} ({{ substr($preview['source']['model_sheet_sha256'], 0, 12) }}).</div>
                            @foreach($sheet['cells'] as $cell)
                                @include('video-projects.partials.moto-reference-decisions', ['reference' => $cell['reference'], 'editable' => in_array($cell['state']['status'], \App\Services\Video\PartsDetailService::ANSWERABLE, true)])
                                @if(($accepted = $cell['state']['review']) !== null && in_array($cell['state']['status'], ['prompt_ready', 'prompt_confirm'], true))
                                    <details class="vr-changes" @if($cell['state']['status'] === 'prompt_confirm') open @endif>
                                        <summary>{{ $cell['reference']['key'] }} — AI {{ $accepted['edit_status'] === 'unchanged' ? 'giữ nguyên nháp' : 'viết lại' }} (lượt rev {{ $accepted['revision'] }})@if($cell['state']['confirmed'] !== null) · đã xác nhận @endif</summary>
                                        @foreach($accepted['changes'] as $change)
                                            @continue($change['kind'] === 'kept')
                                            <div class="vr-change {{ $change['kind'] === 'added' ? 'add' : 'rewrite' }} visual" title="{{ $factText($cell['reference'], $change['fact_refs']) }}">
                                                <b>{{ $change['kind'] === 'added' ? 'Thêm' : 'Viết lại '.implode(', ', $change['source_line_ids']) }}</b>: {{ $change['text'] }}
                                                <div class="va-lbl" style="font-weight:400">Căn cứ: {{ $factText($cell['reference'], $change['fact_refs']) }}</div>
                                                @if(($change['unsupported'] ?? []) !== [])
                                                    <div class="va-lbl" style="font-weight:400;color:var(--vp-red)">Từ không có trong dữ kiện dẫn: {{ implode(', ', $change['unsupported']) }}</div>
                                                @endif
                                            </div>
                                        @endforeach
                                        @foreach($accepted['left_out'] ?? [] as $item)
                                            <div class="vr-change visual">
                                                <b>AI bỏ {{ $item['fact_ref'] }}</b>: {{ $item['what'] }} — {{ $item['reason'] }}
                                                <div class="va-lbl" style="font-weight:400">Dữ kiện gốc: {{ $item['value'] }}</div>
                                            </div>
                                        @endforeach
                                        @if($cell['state']['status'] === 'prompt_confirm' && $stage !== null)
                                            @php
                                                $recheckId = 'refRecheck'.$cell['reference']['id'];
                                            @endphp
                                            @if(! in_array(\App\Services\Video\PartsDetailService::CORRECTION_QUESTION, array_column($cell['reference']['requirement']['decisions'], 'question'), true))
                                                <form method="POST" action="{{ route('video-projects.parts-detail', [$id, 'answer']) }}" onsubmit="return vpLockForm(this)">
                                                    @csrf
                                                    <input type="hidden" name="hash" value="{{ $preview['hash'] }}"><input type="hidden" name="reference_id" value="{{ $cell['reference']['id'] }}">
                                                    <input type="hidden" name="decisions[0][question]" value="{{ \App\Services\Video\PartsDetailService::CORRECTION_QUESTION }}">
                                                    <textarea class="va-ta" name="decisions[0][answer]" rows="2" maxlength="1000" required placeholder="Điều AI viết sai ở ô này và cần sửa thế nào"></textarea>
                                                    <button class="vp-btn sm" data-busy="Đang lưu…">Lưu điều cần sửa (không gọi AI)</button>
                                                </form>
                                            @endif
                                            @if($cell['estimate'] !== null)
                                                <form method="POST" action="{{ route('video-projects.parts-detail', [$id, 'review']) }}" id="{{ $recheckId }}" data-modal="confirm{{ $recheckId }}" onsubmit="return vpLockForm(this)" hidden>
                                                    @csrf
                                                    <input type="hidden" name="plan_id" value="{{ $stage->id }}"><input type="hidden" name="cells[]" value="{{ $cell['reference']['id'] }}">
                                                    <input type="hidden" name="operation_id" value="{{ \Illuminate\Support\Str::uuid() }}"><input type="hidden" name="retry" value="1"><input type="hidden" name="confirm_cost" value="1">
                                                </form>
                                                <button type="button" class="vp-btn sm" data-toggle="modal" data-target="#confirm{{ $recheckId }}" data-busy="Đang kiểm…">Kiểm lại ô này</button>
                                                @include('modal.confirm_action', ['id' => 'confirm'.$recheckId, 'form' => $recheckId, 'content' => 'Gọi '.$cell['estimate']['model'].' kiểm lại prompt của 1 ô — tác vụ này tính tiền ('.$cell['estimate']['text'].').'])
                                            @endif
                                        @endif
                                    </details>
                                @endif
                                @foreach($cell['reference']['shots'] as $shotId)
                                    @if(($shotPrompts[$shotId] ?? '') !== '')
                                        <details class="vr-changes"><summary>Prompt ảnh của shot {{ $shotId }} (nguyên văn, để truy vết)</summary><textarea class="va-ta" readonly>{{ $shotPrompts[$shotId] }}</textarea></details>
                                    @endif
                                @endforeach
                            @endforeach

                            <div class="va-foot">
                                @if($sheet['review_cells'] !== [] && $stage !== null && $sheet['estimate'] !== null)
                                    <form method="POST" action="{{ route('video-projects.parts-detail', [$id, 'review']) }}" id="{{ $reviewId }}" data-modal="confirm{{ $reviewId }}" onsubmit="return vpLockForm(this)" hidden>
                                        @csrf
                                        <input type="hidden" name="plan_id" value="{{ $stage->id }}">
                                        @foreach($sheet['review_cells'] as $cellId)<input type="hidden" name="cells[]" value="{{ $cellId }}">@endforeach
                                        <input type="hidden" name="operation_id" value="{{ \Illuminate\Support\Str::uuid() }}">
                                        @if(collect($sheet['cells'])->contains(fn ($c) => $c['state']['status'] === 'prompt_failed'))<input type="hidden" name="retry" value="1">@endif
                                        <input type="hidden" name="confirm_cost" value="1">
                                    </form>
                                    <button type="button" class="vp-btn sm pri" data-toggle="modal" data-target="#confirm{{ $reviewId }}" data-busy="Đang kiểm…">Kiểm & hoàn thiện prompt ({{ count($sheet['review_cells']) }} ô)</button>
                                    @include('modal.confirm_action', ['id' => 'confirm'.$reviewId, 'form' => $reviewId, 'content' => 'Gọi '.$sheet['estimate']['model'].' (effort '.$sheet['estimate']['effort'].') kiểm, bổ sung có căn cứ và viết lại prompt của '.count($sheet['review_cells']).' ô — tác vụ này tính tiền ('.$sheet['estimate']['text'].').'])
                                @elseif($sheet['confirm_cells'] !== [] && $stage !== null)
                                    <form method="POST" action="{{ route('video-projects.parts-detail', [$id, 'accept']) }}" onsubmit="return vpLockForm(this)">
                                        @csrf
                                        <input type="hidden" name="plan_id" value="{{ $stage->id }}">
                                        @foreach($sheet['confirm_cells'] as $cellId => $reviewStage)<input type="hidden" name="reviews[{{ $cellId }}]" value="{{ $reviewStage }}">@endforeach
                                        <button class="vp-btn sm ok" data-busy="Đang lưu…">✓ Đã đọc — xác nhận nội dung AI viết lại ({{ count($sheet['confirm_cells']) }} ô)</button>
                                    </form>
                                @elseif($sheet['renderable'])
                                    <span class="va-tag ok">Prompt mọi ô đã qua kiểm</span>
                                @elseif($stage === null)
                                    <span class="va-tag">Lập kế hoạch trước khi kiểm prompt</span>
                                @endif
                            </div>
                        </div>

                        <div class="va-row-set">
                            <form method="POST" action="{{ route('video-projects.parts-detail', [$id, 'render']) }}" id="{{ $renderId }}" data-modal="confirm{{ $renderId }}" onsubmit="return vpLockForm(this)" hidden>
                                @csrf
                                <input type="hidden" name="plan_id" value="{{ $stage?->id }}">
                                @foreach($sheet['cell_ids'] as $cellId)<input type="hidden" name="cells[]" value="{{ $cellId }}">@endforeach
                                <input type="hidden" name="sheet_hash" value="{{ $sheet['hash'] }}">
                                <input type="hidden" name="operation_id" value="{{ \Illuminate\Support\Str::uuid() }}">
                                <input type="hidden" name="confirm_cost" value="1">
                            </form>
                            <div class="va-fields">
                                <div class="va-field"><label>Size <em>(theo số ảnh)</em></label><select class="ctl" disabled><option>{{ $pixels($sheet['layout']['size']) }} ({{ $oneImage($sheet['layout']) ? 'một ảnh' : $sheet['layout']['rows'].' × '.$sheet['layout']['columns'] }})</option></select></div>
                                <div class="va-field">
                                    <label>Model</label>
                                    <select class="ctl" name="model" form="{{ $renderId }}" required data-ref-model>
                                        @foreach($ref['models'] as $m)
                                            <option value="{{ $m['model'] }}" @selected($loop->first)>{{ $m['label'] }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="va-field">
                                    <label>Quality</label>
                                    <select class="ctl" name="quality" form="{{ $renderId }}" required data-ref-quality>
                                        @foreach(collect($ref['models'])->pluck('controls.qualities')->flatten()->unique() as $quality)
                                            <option value="{{ $quality }}" @selected($quality === 'high')>{{ $quality }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="va-field"><label>Variations <em>(cố định)</em></label><select class="ctl" disabled><option>1</option></select></div>
                            </div>
                            <div class="va-lbl" style="font-weight:400" data-ref-price data-prices='@json($ref['prices'])'>Dự toán: —</div>
                            <div class="va-foot">
                                <button type="button" class="vp-btn pri" data-toggle="modal" data-target="#confirm{{ $renderId }}"
                                        @if(! $sheet['renderable'] || $stage === null) disabled title="Cần kế hoạch đã lập và prompt mọi ô đã qua kiểm" @endif
                                        data-busy="Đang render…">Render master</button>
                                @include('modal.confirm_action', ['id' => 'confirm'.$renderId, 'form' => $renderId, 'content' => 'Gửi prompt master '.$sheet['id'].' ('.($oneImage($sheet['layout']) ? 'một ảnh, không lưới' : count($sheet['cell_ids']).' ảnh, lưới '.$sheet['layout']['rows'].' × '.$sheet['layout']['columns']).') kèm ảnh đầu vào cho model đã chọn: một request, một ảnh '.$pixels($sheet['layout']['size']).' — TÁC VỤ NÀY TÍNH TIỀN.', 'detail' => 'Xem dự toán ở cột Render.'])
                            </div>
                        </div>

                        <div class="va-row-cands">
                            <div class="va-lbl" style="font-weight:400;color:var(--vp-dim)">Chưa có master cho các ô này — master đã render nằm ở các hàng bên dưới.</div>
                        </div>
                    </div>
                @empty
                    <div class="va-row"><div class="va-lbl" style="font-weight:400">Không còn ô nào cần tạo master.</div></div>
                @endforelse

                @foreach($ref['masters'] as $entry)
                    @php
                        $image = $entry['image'];
                        $spec = (array) $image->prompt_spec_json;
                    @endphp
                    <div class="va-row">
                        <div class="va-row-who">
                            <b>{{ $image->image_code }}</b>
                            <span class="va-tag">{{ $image->status }}</span>
                            <span class="va-tag {{ ($spec['regime'] ?? '') === 'grey' ? 'mute' : 'blue' }}">{{ $regimeLabel[$spec['regime'] ?? 'colour'] ?? '' }}</span>
                            @foreach((array) ($spec['cells'] ?? []) as $index => $cell)
                                @php
                                    $current = $references[$cell['id']] ?? null;
                                @endphp
                                <div class="vr-cell">
                                    <div class="va-lbl">Ô {{ $index + 1 }} · {{ $current['key'] ?? $cell['id'] }}</div>
                                    @if($current === null || $current['fingerprint'] !== $cell['fingerprint'])
                                        <span class="va-tag dg">KHÔNG CÒN KHỚP KẾ HOẠCH</span>
                                    @else
                                        {!! $tag($ref['state'][$cell['id']]['status']) !!}
                                    @endif
                                </div>
                            @endforeach
                        </div>
                        <div class="va-row-prompt">
                            <div class="va-lbl">PROMPT ĐÃ GỬI</div>
                            <textarea class="va-ta" readonly>{{ $spec['prompt'] ?? '' }}</textarea>
                            <div class="va-lbl" style="font-weight:400">Ảnh đầu vào: @foreach((array) ($spec['sources'] ?? []) as $source){{ $source['kind'] ?? 'ảnh' }} {{ substr((string) $source['sha256'], 0, 12) }} @endforeach</div>
                        </div>
                        <div class="va-row-set">
                            <div class="va-lbl" style="font-weight:400">{{ $spec['model'] ?? '' }} · {{ $spec['quality'] ?? '' }} · {{ $spec['size'] ?? '' }} · {{ $image->created_at?->format('Y-m-d H:i') }}</div>
                            @if($entry['task'] !== null)
                                @include('video-projects.partials.task-state', ['task' => $entry['task'], 'projectId' => $id])
                            @endif
                            @if($image->render_error)
                                <div class="alert alert-danger">{{ $image->render_error }}</div>
                            @endif
                        </div>
                        <div class="va-row-cands">
                            @forelse($entry['artifacts'] as $item)
                                @php
                                    $artifact = $item['artifact'];
                                @endphp
                                <div class="va-cell">
                                    <span class="code">{{ $image->image_code }}</span>
                                    <span class="va-tag">{{ \App\Enums\DesignImageStatus::tryFrom($image->status)?->label() ?? $image->status }}</span>
                                    <span class="grow"></span>
                                    <span class="d">{{ $spec['model'] ?? '' }} · {{ $spec['size'] ?? '' }}</span>
                                </div>
                                <div class="va-cands">
                                    <div class="va-cand">
                                        <img src="{{ route('video-artifacts.show', $artifact->id) }}" alt="Master {{ $image->image_code }}" width="{{ $artifact->width }}" height="{{ $artifact->height }}">
                                        <div class="meta">
                                            <span>{{ $image->image_code }}</span>
                                            <span>Model: {{ $spec['model'] ?? '' }}</span>
                                            <span>{{ $artifact->width }}×{{ $artifact->height }}</span>
                                            <span>Created: {{ $artifact->created_at?->format('Y-m-d H:i:s') ?? '—' }}</span>
                                        </div>
                                    </div>
                                </div>
                                @if(($item['processing']['status'] ?? null) !== null && ($item['processing']['status'] ?? null) !== 'processed')
                                    <div class="alert alert-danger">{{ $item['processing']['error'] ?? 'Xử lý master lỗi.' }}</div>
                                @endif
                                <div class="vr-crops">
                                    @foreach($item['crops'] as $crop)
                                        @php
                                            $decision = $ref['decisions']->get($crop['id'])?->first()?->decision;
                                            $inUse = isset($ref['selected_crops'][$crop['id']]);
                                        @endphp
                                        <div class="vr-crop">
                                            @if($oneImage((array) ($spec['layout'] ?? [])))
                                                <div class="va-lbl" style="font-weight:400">Một ảnh nguyên tấm, không cắt</div>
                                            @else
                                                <a href="{{ $crop['url'] }}" target="_blank" rel="noopener" class="va-cand" title="{{ $crop['view_id'] }} · ({{ $crop['x'] }}, {{ $crop['y'] }}) · {{ $crop['width'] }}×{{ $crop['height'] }}">
                                                    <img src="{{ $crop['url'] }}" alt="{{ $crop['view_id'] }}" width="{{ $crop['width'] }}" height="{{ $crop['height'] }}" loading="lazy">
                                                    <span class="va-tag vr-crop-tag">Ô {{ array_search($crop['view_id'], array_column((array) ($spec['cells'] ?? []), 'id'), true) + 1 }}</span>
                                                </a>
                                            @endif
                                            <div class="va-lbl" style="font-weight:400">{{ $references[$crop['view_id']]['key'] ?? $crop['view_id'] }}</div>
                                            @if($inUse)
                                                <span class="va-tag ok">Đã duyệt · đang dùng</span>
                                            @elseif($decision !== null)
                                                <span class="va-tag {{ $decision === 'approved' ? 'ok' : 'dg' }}">{{ $decision === 'approved' ? 'Đã duyệt' : 'Đã từ chối' }}</span>
                                            @endif
                                            <div class="va-foot">
                                                @foreach(['approved' => ['ok', '✓ Duyệt'], 'rejected' => ['dg', '✕ Từ chối']] as $value => [$tone, $label])
                                                    <form method="POST" action="{{ route('video-projects.parts-detail', [$id, 'decide']) }}" onsubmit="return vpLockForm(this)">
                                                        @csrf
                                                        <input type="hidden" name="artifact_id" value="{{ $crop['id'] }}"><input type="hidden" name="decision" value="{{ $value }}">
                                                        <button class="vp-btn sm {{ $tone }}" data-busy="…" @disabled($decision === $value && ($value === 'rejected' || $inUse))>{{ $label }}</button>
                                                    </form>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                                @if($image->status === 'rendered' && (! $oneImage((array) ($spec['layout'] ?? [])) || $item['crops'] === []))
                                    <form method="POST" action="{{ route('video-projects.parts-detail', [$id, 'process']) }}" onsubmit="return vpLockForm(this)">
                                        @csrf
                                        <input type="hidden" name="artifact_id" value="{{ $artifact->id }}">
                                        <button class="vp-btn sm" data-busy="Đang xử lý…">{{ $oneImage((array) ($spec['layout'] ?? [])) ? 'Lưu ảnh để duyệt' : ($item['crops'] === [] ? 'Cắt ảnh từ master' : 'Xử lý lại từ master') }}</button>
                                    </form>
                                @endif
                            @empty
                                <div class="va-lbl" style="font-weight:400;color:var(--vp-dim)">Chưa có ảnh.</div>
                            @endforelse
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
