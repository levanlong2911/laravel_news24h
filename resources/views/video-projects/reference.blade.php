@extends('layouts.base', ['title' => 'Reference Views'])
@section('title', 'Reference Views')

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
        $menuViews = collect($referenceViewCases)->map(fn ($view) => $view->value);
        $rows = collect($referenceViewCases)
            ->map(fn ($view) => ['key' => $view->value, 'label' => $view->displayLabel(), 'sheet' => false])
            ->merge(collect($referenceViews)
                ->whereNotIn('reference_mode', [\App\Video\Reference\ReferenceSheet::MODE, \App\Services\Video\ReferencePromptWriter::PLAN_MODE])
                ->pluck('view_key')->filter()->unique()->diff($menuViews)
                ->map(fn ($view) => ['key' => $view, 'label' => \App\Video\Reference\ReferenceView::tryFrom((string) $view)?->displayLabel() ?? $view, 'sheet' => false]))
            ->push(['key' => $referenceSheet['option'], 'label' => 'Bộ 6 góc trên 1 ảnh', 'sheet' => true])
            ->values();
        $cellsOf = fn (array $row) => collect($referenceViews)->filter(fn ($cell) => $row['sheet']
            ? $cell['reference_mode'] === \App\Video\Reference\ReferenceSheet::MODE
            : ! in_array($cell['reference_mode'], [\App\Video\Reference\ReferenceSheet::MODE, \App\Services\Video\ReferencePromptWriter::PLAN_MODE], true) && $cell['view_key'] === $row['key']);
        $approvedViews = collect($referenceViews)
            ->where('status', \App\Enums\DesignImageStatus::APPROVED->value)
            ->where('reference_mode', '!==', \App\Video\Reference\ReferenceSheet::MODE)
            ->pluck('view_key')->filter()->unique();
    @endphp

    <div class="va-page">
        <div>
            <h1>Reference Views</h1>
            <p>Render các góc tham chiếu từ canonical anchor đã duyệt — mỗi góc một hàng</p>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            <span class="va-tag {{ $approvedViews->count() === count($referenceViewCases) ? 'ok' : 'mute' }}">
                {{ $approvedViews->count() }} / {{ count($referenceViewCases) }} GÓC ĐÃ DUYỆT
            </span>
            <a class="vp-btn" href="{{ route('video-projects.anchor', $id) }}">← Ảnh neo</a>
            <a class="vp-btn" href="{{ route('video-projects.environment', $id) }}">Environment Library →</a>
            <a class="vp-btn" href="{{ route('video-projects.scene', $id) }}">Storyboard →</a>
        </div>
    </div>

    @if($designFirst ?? false)
        <div class="vp-panel" id="referenceLockPanel">
            <div class="va-head">
                <b>CHỐT BỘ REFERENCE</b>
                <em>bắt buộc trước khi viết kịch bản</em>
                <span class="grow"></span>
                @if($referenceLock)
                    <span class="va-tag ok">ĐÃ CHỐT {{ count($referenceLock['references']) }} ẢNH</span>
                @else
                    <span class="va-tag dg">CHƯA CHỐT</span>
                @endif
                <form method="POST" action="{{ route('video-projects.reference-lock', $id) }}" onsubmit="return vpLockForm(this)">
                    @csrf
                    <button class="vp-btn sm pri" @disabled($lockableReferences === [])
                            title="{{ $lockableReferences === [] ? 'Duyệt ít nhất một ảnh Reference từ ảnh anchor hiện hành trước' : '' }}">
                        {{ $referenceLock ? 'Chốt lại bộ Reference → Viết kịch bản' : 'Chốt bộ Reference → Viết kịch bản' }}
                    </button>
                </form>
            </div>
            <div class="va-body">
                <div class="va-lbl" style="font-weight:400;color:var(--vp-dim)">
                    Ảnh Reference đã duyệt từ ảnh anchor hiện hành ({{ count($lockableReferences) }}):
                    @forelse($lockableReferences as $row)
                        <br>{{ \App\Video\Reference\ReferenceView::tryFrom($row['view'])?->displayLabel() ?? $row['view'] }} — {{ $row['image_id'] }}
                    @empty
                        <br>chưa có — duyệt ít nhất một ảnh rồi bấm Chốt.
                    @endforelse
                    @if($referenceLock)
                        <br>Đã chốt lúc {{ $referenceLock['approved_at'] }}. Khi duyệt lại anchor hoặc đổi thiết kế, bộ chốt này mất hiệu lực.
                    @endif
                </div>
            </div>
        </div>
    @endif

    <div class="vp-panel" id="referenceAnchorPanel">
        <div class="va-head">
            <b>APPROVED CANONICAL ANCHOR</b>
            <span class="grow"></span>
            @if($approvedAnchor?->artifact)
                <span class="va-tag ok">APPROVED</span>
            @else
                <span class="va-tag dg">CHƯA DUYỆT</span>
            @endif
        </div>

        <div class="va-body">
            @if($approvedAnchor?->artifact)
                @php
                    $anchorUrl = $approvedAnchor->artifact->storage_disk === 'public'
                        ? $approvedAnchor->artifact->storage_path
                        : route('video-artifacts.show', $approvedAnchor->artifact->id);
                    $source = $referenceSource ?? [];
                @endphp
                <div class="vr-anchor">
                    <a href="{{ $anchorUrl }}" target="_blank" rel="noopener" title="Mở ảnh gốc">
                        <img src="{{ $anchorUrl }}" alt="{{ $approvedAnchor->image_code }}">
                    </a>
                    <div>
                        <div class="va-lbl" style="font-weight:400;color:var(--vp-dim)">{{ $approvedAnchor->image_code }}</div>
                        <div class="va-lbl" style="font-weight:400;color:var(--vp-dim)">Artifact: {{ $approvedAnchor->artifact->id }}</div>
                        <div class="va-lbl" style="font-weight:400;color:var(--vp-dim)">SHA256: {{ $approvedAnchor->artifact->sha256 }}</div>
                        @if(($source['error'] ?? null) !== null)
                            <div class="va-lbl" style="font-weight:400;color:var(--vp-red)">Nguồn thiết kế: {{ $source['error'] }}</div>
                        @elseif(($source['kind'] ?? null) === \App\Services\Video\ReferencePromptWriter::SOURCE_DESIGN)
                            <div class="va-lbl" style="font-weight:400;color:var(--vp-dim)">
                                Nguồn: bản thiết kế tàu rev {{ $source['design_revision'] ?? '—' }}
                                · {{ $source['canonical'] ? 'có hình học chuẩn (canonical)' : 'chưa có canonical — dùng gói cũ' }}
                                · hash {{ substr((string) ($source['design_content_hash'] ?? ''), 0, 12) }}
                            </div>
                        @elseif(($source['kind'] ?? null) !== null)
                            <div class="va-lbl" style="font-weight:400;color:var(--vp-dim)">Nguồn: kịch bản (luồng cũ)</div>
                        @endif
                    </div>
                </div>
            @else
                <div class="va-lbl" style="color:var(--vp-red);font-weight:400">
                    {{ __('messages.no_approved_anchor') }}
                </div>
            @endif
        </div>
    </div>

    @if($referencePlan !== null)
        @php
            $plan = $referencePlan['plan'];
            $viewLabel = fn (string $view): string => $view === \App\Video\Reference\ReferenceView::DESIGN_ANCHOR_VIEW
                ? 'Góc của ảnh anchor (3/4 mũi, mạn trái)'
                : (\App\Video\Reference\ReferenceView::tryFrom($view)?->displayLabel() ?? $view);
            $presentationLabel = fn (?string $value): string => match ($value) {
                \App\Services\Video\ReferencePromptWriter::UNFINISHED => 'Chưa hoàn thiện',
                \App\Services\Video\ReferencePromptWriter::FINISHED => 'Hoàn thiện',
                default => (string) $value,
            };
            $shotCount = collect($referencePlan['needs'])->pluck('shot_code')->unique()->count();
        @endphp
        <div class="vp-panel" id="referencePlan">
            <div class="va-head">
                <b>REFERENCE THEO SHOT</b>
                <em>storyboard production → phân cảnh → thiết kế → anchor</em>
                <span class="grow"></span>
                @if($referencePlan['source'])
                    <span class="va-tag mute">Storyboard bản {{ $referencePlan['source']['storyboard_revision'] }} · Thiết kế rev {{ $referencePlan['source']['design_revision'] }}</span>
                @endif
                @if($referencePlan['source'] && $referencePlan['needs'] !== [] && ($referencePlan['run']['status'] ?? null) !== 'running')
                    <form method="POST" action="{{ route('video-projects.reference-plan', $id) }}" id="referencePlanForm"
                          data-modal="confirmReferencePlan" onsubmit="return vpLockForm(this)" hidden>
                        @csrf
                        @if($plan !== null)
                            <input type="hidden" name="force" value="1">
                        @endif
                    </form>
                    <button type="button" class="vp-btn sm pri" data-toggle="modal" data-target="#confirmReferencePlan" data-busy="Claude đang viết…">
                        {{ $plan === null ? 'Đề xuất góc và viết Reference Prompt' : 'Viết lại bằng Claude' }}
                    </button>
                    @include('modal.confirm_action', [
                        'id' => 'confirmReferencePlan',
                        'form' => 'referencePlanForm',
                        'content' => 'Gọi '.$referencePlan['model'].' (effort '.$referencePlan['effort'].') MỘT lượt cho '.count($referencePlan['needs']).' nhu cầu ảnh của '.$shotCount
                            .' shot: gửi ảnh anchor và toàn bộ thiết kế rev '.$referencePlan['source']['design_revision'].' để đề xuất góc và viết mô tả — TÁC VỤ NÀY TÍNH TIỀN.',
                        'detail' => ($plan === null ? 'Cùng nguồn và nhu cầu thì lần sau dùng lại kết quả, không gọi lại. ' : 'Viết lại tạo bộ đề xuất mới; bộ đang dùng giữ nguyên nếu lượt mới lỗi. ')
                            .'Không tự render ảnh nào.',
                    ])
                @elseif(($referencePlan['run']['status'] ?? null) === 'running')
                    <button class="vp-btn sm" disabled>Claude đang viết…</button>
                @endif
            </div>
            <div class="va-body">
                @if($referencePlan['error'] !== null)
                    <div class="va-lbl" style="color:var(--vp-red);font-weight:400">{{ __('messages.'.explode('|', $referencePlan['error'], 2)[0]) }}</div>
                @endif
                @if(($referencePlan['run']['status'] ?? null) === 'failed')
                    <div class="va-lbl" style="color:var(--vp-red);font-weight:400">Lượt Claude lúc {{ $referencePlan['run']['at'] }} không áp dụng: {{ $referencePlan['run']['error'] }}</div>
                @endif
                @if($plan !== null)
                    <div class="va-lbl" style="font-weight:400;color:var(--vp-dim)">
                        Bộ đề xuất lúc {{ $plan['at'] }} · {{ $plan['author_model'] }}
                        @isset($plan['usage']['tokens_in']) · {{ $plan['usage']['tokens_in'] }} token vào · {{ $plan['usage']['tokens_out'] }} token ra @endisset
                        — văn xuôi do Claude viết: code kiểm nguồn, góc, trạng thái, số liệu và id, không chứng minh được nghĩa; đọc kỹ trước khi duyệt.
                    </div>
                @elseif($referencePlan['source'] !== null)
                    <div class="va-lbl" style="font-weight:400;color:var(--vp-dim)">Nhu cầu ảnh của storyboard production ({{ count($referencePlan['needs']) }}) — chưa có bộ đề xuất:</div>
                    <table class="vr-needs">
                        <tr><th>Shot</th><th>Loại</th><th>Góc</th><th>Hoàn thiện</th><th>Trạng thái</th><th>Phần cần thấy</th></tr>
                        @foreach($referencePlan['needs'] as $need)
                            <tr>
                                <td>{{ $need['shot_code'] }}</td>
                                <td>{{ $need['kind'] === 'design' ? 'bản vẽ / mô hình' : 'tàu thật' }}</td>
                                <td>{{ $need['view'] === null ? 'chưa nêu' : $viewLabel($need['view']) }}</td>
                                <td>{{ $presentationLabel($need['presentation']) }}</td>
                                <td>{{ $need['state'] }}</td>
                                <td>{{ $need['must_show'] }}</td>
                            </tr>
                        @endforeach
                    </table>
                @endif
                @if($referencePlan['interior'] !== [])
                    <div class="va-lbl" style="font-weight:400;color:var(--vp-dim)">
                        Nhu cầu trong phòng của tàu — không lập ảnh ngoại thất, dùng tấm nền phòng ở Environment Library:
                        {{ collect($referencePlan['interior'])->map(fn ($need) => $need['shot_code'].' ('.$need['location'].')')->implode(', ') }}
                    </div>
                @endif
            </div>
        </div>

        @if($plan !== null)
            <div class="ve-grid vr-plan">
                <div class="vs-h"><b>1</b> Đề xuất của Claude</div>
                <div class="vs-h"><b>2</b> Render settings</div>
                <div class="vs-h"><b>3</b> Ảnh của mục này</div>

                @foreach($plan['items'] as $item)
                    @php
                        $slug = $item['request_id'];
                        $form = 'planForm_'.$slug;
                        $review = $item['recommendation'] === \App\Services\Video\ReferencePromptWriter::NEEDS_REVIEW;
                        $renderNew = $item['recommendation'] === \App\Services\Video\ReferencePromptWriter::RENDER_NEW;
                        $skipped = $item['decision'] === \App\Services\Video\ReferencePromptWriter::SKIPPED;
                    @endphp
                    <div class="vs-row" @if($renderNew) data-ref-row="{{ $slug }}" data-prompts="{{ json_encode($item['prompts']) }}" data-locked="{{ $skipped ? '1' : '0' }}" @endif>
                        <div class="vs-c">
                            <span class="ve-name">{{ $viewLabel($item['view']) }}</span>
                            <span class="va-tag {{ $item['presentation'] === \App\Services\Video\ReferencePromptWriter::FINISHED ? 'ok' : 'amber' }}">{{ $presentationLabel($item['presentation']) }}</span>
                            <span class="va-tag mute">{{ $item['kind'] === 'design' ? 'bản vẽ / mô hình' : 'tàu thật' }}</span>
                            @if($review)
                                <span class="va-tag dg">Cần xử lý</span>
                            @elseif($item['findings'] !== [])
                                <span class="va-tag amber">Có ghi chú — đọc trước khi render</span>
                            @endif
                            @if($skipped)
                                <span class="va-tag mute">ĐÃ BỎ CHỌN</span>
                            @endif
                            <span class="ve-key" style="display:block;margin-top:4px">{{ $item['request_id'] }} · Shot: {{ implode(', ', $item['shots']) }}</span>
                            <div class="va-lbl" style="font-weight:400;margin-top:6px"><b>Trạng thái:</b> {{ $item['state_details'] }}</div>
                            <div class="va-lbl" style="font-weight:400"><b>Lý do:</b> {{ $item['reason'] }}</div>
                            @foreach($item['findings'] as $finding)
                                <div class="va-lbl vr-finding {{ $review ? 'dg' : 'amber' }}"><b>Ghi chú:</b> {{ $finding }}</div>
                            @endforeach
                            @if($item['description'] !== '')
                                <div class="va-lbl" style="margin-top:6px">MÔ TẢ (Claude viết)</div>
                                <textarea class="va-ta" readonly>{{ $item['description'] }}</textarea>
                            @endif
                            @unless($review)
                                <div class="va-foot" style="padding-left:0;padding-right:0">
                                    <form method="POST" action="{{ route('video-projects.reference-plan-decide', $id) }}">
                                        @csrf
                                        <input type="hidden" name="reference_plan_stage_id" value="{{ $plan['stage_id'] }}">
                                        <input type="hidden" name="request_id" value="{{ $item['request_id'] }}">
                                        <input type="hidden" name="status" value="{{ $skipped ? \App\Services\Video\ReferencePromptWriter::APPROVED : \App\Services\Video\ReferencePromptWriter::SKIPPED }}">
                                        <button class="vp-btn sm">{{ $skipped ? 'Dùng lại' : 'Bỏ chọn' }}</button>
                                    </form>
                                </div>
                            @endunless
                        </div>

                        <div class="vs-c">
                            @if($renderNew)
                                <form method="POST" action="{{ route('video-projects.reference-plan-render', $id) }}"
                                      id="{{ $form }}" data-modal="confirmPlan_{{ $slug }}" onsubmit="return vpLockForm(this)" hidden>
                                    @csrf
                                    <input type="hidden" name="reference_plan_stage_id" value="{{ $plan['stage_id'] }}">
                                    <input type="hidden" name="request_id" value="{{ $item['request_id'] }}">
                                    <input type="hidden" name="prompt_sha256" data-role="sha" value="">
                                </form>
                                <div class="ve-set">
                                    @include('video-projects.partials.reference-render-fields', [
                                        'form' => $form, 'disabled' => false,
                                        'defaultEnvironment' => \App\Video\Reference\ReferenceEnvironment::NEUTRAL_STUDIO->value,
                                        'defaultSize' => null, 'onlyOne' => false,
                                    ])
                                </div>
                                <div class="va-lbl" style="margin-top:8px">PROMPT</span></div>
                                <textarea class="va-ta" data-role="prompt" readonly></textarea>
                                <div class="va-count"><span data-role="count">0</span> ký tự</div>
                                <div class="va-foot" style="padding-left:0;padding-right:0">
                                    <button type="button" class="vp-btn pri" data-role="render" data-toggle="modal" data-target="#confirmPlan_{{ $slug }}"
                                            data-busy="Đang render…" disabled>Render</button>
                                </div>
                                @if($skipped)
                                    <div class="ve-locked">Đã bỏ chọn — bấm "Dùng lại" để render.</div>
                                @endif
                                @include('modal.confirm_action', [
                                    'id' => 'confirmPlan_'.$slug,
                                    'form' => $form,
                                    'content' => 'Render "'.$viewLabel($item['view']).' · '.$presentationLabel($item['presentation']).'" từ ảnh anchor bằng đúng prompt đang hiển thị — TÁC VỤ NÀY TÍNH TIỀN.',
                                    'detail' => 'Ảnh giai đoạn thi công khó — nên thử một ảnh trước khi tạo nhiều.',
                                ])
                            @else
                                <div class="ve-locked">
                                    @switch(true)
                                        @case($review) Cần xử lý — không render. @break
                                        @case($item['recommendation'] === \App\Services\Video\ReferencePromptWriter::REUSE_ANCHOR) Dùng thẳng ảnh anchor — không render. @break
                                        @default Dùng lại ảnh đã duyệt {{ $item['existing_artifact_id'] }} — không render.
                                    @endswitch
                                </div>
                            @endif
                        </div>

                        <div class="vs-c">
                            @include('video-projects.partials.reference-cards', [
                                'rowCells' => collect($item['cells']),
                                'empty' => 'Chưa có ảnh cho mục này.',
                                'caption' => fn ($cell) => $viewLabel($item['view']).' · '.$presentationLabel($item['presentation']),
                            ])
                        </div>
                    </div>
                @endforeach
            </div>

            @if($plan['unresolved'] !== [])
                <div class="vp-panel" style="margin-top:10px">
                    <div class="va-head"><b>NHU CẦU CHƯA GIẢI QUYẾT</b><em>Claude không xác định được — cần người quyết</em></div>
                    <div class="va-body">
                        @foreach($plan['unresolved'] as $row)
                            <div class="va-lbl" style="font-weight:400">{{ implode(', ', $row['need_ids']) }} — {{ $row['reason'] }}</div>
                        @endforeach
                    </div>
                </div>
            @endif
        @endif

        <div class="va-lbl" style="margin:14px 0 6px">THEO GÓC (luồng cũ — gpt viết VIEW GEOMETRY từng góc)</div>
    @endif

    <div class="ve-grid" id="referenceRows">

        <div class="vs-h"><b>1</b> Reference prompt</div>
        <div class="vs-h"><b>2</b> Render settings</div>
        <div class="vs-h"><b>3</b> Rendered views</div>

        @foreach($rows as $row)
            @php
                $key = $row['key'];
                $slug = preg_replace('/[^a-z0-9_]/', '_', $key);
                $form = 'refForm_'.$slug;
                $promptForm = 'refPromptForm_'.$slug;
                $rowCells = $cellsOf($row);
            @endphp

            <div class="vs-row" data-ref-row="{{ $slug }}" data-view="{{ $key }}" data-sheet="{{ $row['sheet'] ? '1' : '0' }}">

                <div class="vs-c">
                    <span class="ve-name">{{ $row['label'] }}</span>
                    <span class="ve-key">{{ $key }}</span>
                    @if(! $row['sheet'] && $approvedViews->contains($key))
                        <span class="va-tag ok" style="margin-left:6px">ĐÃ DUYỆT</span>
                    @endif

                    <div class="va-lbl" style="margin-top:8px">PROMPT GỬI ĐI
                        <span>{{ $row['sheet'] ? '(code ghép từ ảnh anchor + bản thiết kế, không gọi AI viết prompt · chỉ đọc)' : '(AI viết VIEW GEOMETRY · phần còn lại do PHP ghép)' }}</span>
                    </div>
                    <textarea class="va-ta" data-role="prompt" readonly></textarea>
                    <div class="va-count"><span data-role="count">0</span> ký tự</div>

                    @if($row['sheet'])
                        <div class="va-lbl">THỨ TỰ 6 Ô TRÊN ẢNH <span>(ảnh không vẽ chữ; dùng làm tham chiếu tổng hợp, không dùng làm keyframe hay ảnh một góc)</span></div>
                        <div class="va-sheet-legend">
                            @foreach($referenceSheet['legend'] as $panel)
                                <div><b>{{ $loop->iteration }}</b> {{ $panel['label'] }}</div>
                            @endforeach
                        </div>
                        <div class="va-lbl" data-role="sheet-error" hidden style="color:var(--vp-red);font-weight:400"></div>
                    @else
                        <div class="va-lbl" data-role="blocked" hidden style="color:var(--vp-red);font-weight:400">
                            AI báo nguồn thiết kế hoặc ảnh anchor còn mâu thuẫn hoặc thiếu dữ kiện cho góc này (vẫn cho render):
                        </div>
                        <div data-role="conflicts"></div>
                        <div data-role="warnings"></div>

                        <form method="POST" action="{{ route('video-projects.reference-prompt', $id) }}"
                              id="{{ $promptForm }}" data-modal="confirmRefPrompt_{{ $slug }}"
                              onsubmit="return vpLockForm(this)" hidden>
                            @csrf
                            <input type="hidden" name="view" value="{{ $key }}">
                        </form>
                        <div class="va-foot" style="padding-left:0;padding-right:0">
                            <button type="button" class="vp-btn" data-toggle="modal" data-target="#confirmRefPrompt_{{ $slug }}"
                                    data-busy="Đang viết…" @disabled($approvedAnchor === null)>AI viết prompt</button>
                        </div>
                        @include('modal.confirm_action', [
                            'id' => 'confirmRefPrompt_'.$slug,
                            'form' => $promptForm,
                            'content' => 'Gửi ảnh anchor và gói thiết kế của góc "'.$row['label'].'" cho model viết VIEW GEOMETRY — TÁC VỤ NÀY TÍNH TIỀN.',
                            'detail' => 'Ảnh, nguồn và cấu hình không đổi thì dùng lại prompt đã viết, không gọi model.',
                        ])
                    @endif
                </div>

                <div class="vs-c">
                    <form method="POST" action="{{ route('video-projects.reference', $id) }}"
                          id="{{ $form }}" data-modal="confirmRef_{{ $slug }}"
                          onsubmit="return vpLockForm(this)" hidden>
                        @csrf
                        <input type="hidden" name="view" value="{{ $key }}">
                        <input type="hidden" name="reference_prompt_stage_id" data-role="stage" value="">
                        <input type="hidden" name="prompt_sha256" data-role="sha" value="">
                    </form>

                    <div class="ve-set">
                        @if($row['sheet'])
                            <div class="va-field">
                                <label>Trạng thái</label>
                                <select class="ctl" name="sheet_state" form="{{ $form }}" data-role="sheet-state" @disabled($approvedAnchor === null)>
                                    @foreach(\App\Video\Reference\ReferenceSheet::STATES as $state)
                                        <option value="{{ $state }}"
                                                @selected($state === \App\Video\Reference\ReferenceSheet::STATE_AS_ANCHOR)
                                                @disabled($state === \App\Video\Reference\ReferenceSheet::STATE_FINISHED && ! $referenceSheet['finish_ready'])>{{ \App\Video\Reference\ReferenceSheet::stateLabel($state) }}{{ $state === \App\Video\Reference\ReferenceSheet::STATE_FINISHED && ! $referenceSheet['finish_ready'] ? ' (thiết kế chưa đủ dữ liệu)' : '' }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                        @include('video-projects.partials.reference-render-fields', [
                            'form' => $form,
                            'disabled' => $approvedAnchor === null,
                            'defaultEnvironment' => $row['sheet'] ? $referenceSheet['default_environment'] : null,
                            'defaultSize' => $row['sheet'] ? $referenceSheet['default_size'] : null,
                            'onlyOne' => $row['sheet'],
                        ])
                    </div>

                    <div class="va-foot" style="padding-left:0;padding-right:0">
                        <button type="button" class="vp-btn pri" data-role="render" data-toggle="modal" data-target="#confirmRef_{{ $slug }}"
                                data-busy="Đang render…" disabled>Generate Reference</button>
                    </div>

                    @include('modal.confirm_action', [
                        'id' => 'confirmRef_'.$slug,
                        'form' => $form,
                        'content' => 'Render "'.$row['label'].'" bằng đúng prompt đang hiển thị — TÁC VỤ NÀY TÍNH TIỀN.',
                        'detail' => 'Chi phí được tính theo model, quality và variations.',
                    ])
                </div>

                <div class="vs-c">
                    @include('video-projects.partials.reference-cards', [
                        'rowCells' => $rowCells,
                        'empty' => 'Chưa có ảnh nào cho góc này.',
                        'caption' => fn ($cell) => $row['sheet'] ? \App\Video\Reference\ReferenceSheet::stateLabel((string) $cell['sheet_state']) : $row['label'],
                    ])
                </div>

            </div>
        @endforeach

    </div>
</div>
@endsection

@section('script')
<script src="{{ asset('assets/js/video-producer.js') }}?v={{ filemtime(public_path('assets/js/video-producer.js')) }}"></script>
<script>
(function () {
    var prompts = @json($referencePrompts);
    var sheetUrl = @json(route('video-projects.reference-sheet-preview', $id));
    var ready = {{ $approvedAnchor === null ? 'false' : 'true' }};

    document.querySelectorAll('[data-ref-row]').forEach(function (row) {
        var view = row.dataset.view;
        var sheet = row.dataset.sheet === '1';
        var own = row.dataset.prompts ? JSON.parse(row.dataset.prompts) : null;
        var locked = row.dataset.locked === '1';
        var pick = function (role) { return row.querySelector('[data-role="' + role + '"]'); };
        var box = pick('prompt');
        var count = pick('count');
        var stage = pick('stage');
        var sha = pick('sha');
        var environment = pick('environment');
        var render = pick('render');
        var model = pick('model');
        var quality = pick('quality');
        var ticket = 0;

        function show(text, prompt) {
            box.value = text;
            if (count) { count.textContent = prompt ? text.length : 0; }
            if (render) { render.disabled = !ready || !prompt || locked; }
        }

        function syncSheet() {
            var mine = ++ticket;
            var error = pick('sheet-error');
            sha.value = '';
            error.hidden = true;
            show('Đang ghép prompt…', false);

            var query = new URLSearchParams({ sheet_state: pick('sheet-state').value, environment: environment.value });
            fetch(sheetUrl + '?' + query.toString(), {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            }).then(function (response) {
                return response.json().then(function (body) { return { ok: response.ok, body: body }; });
            }).then(function (result) {
                if (mine !== ticket) { return; }
                if (result.ok && result.body.ok) {
                    sha.value = result.body.prompt_sha256;
                    show(result.body.prompt, true);
                    return;
                }
                var errors = result.body.errors ? Object.values(result.body.errors).flat() : [];
                show('', false);
                error.textContent = result.body.message && errors.length === 0 ? result.body.message : errors.join(' ');
                error.hidden = false;
            }).catch(function () {
                if (mine !== ticket) { return; }
                show('', false);
                error.textContent = 'Không tải được prompt xem trước — thử lại.';
                error.hidden = false;
            });
        }

        function lines(target, rows, prefix) {
            target.innerHTML = '';
            rows.forEach(function (line) {
                var item = document.createElement('div');
                item.className = 'va-lbl';
                item.style.fontWeight = '400';
                item.style.color = 'var(--vp-red)';
                item.textContent = prefix + line;
                target.appendChild(item);
            });
        }

        function sync() {
            if (sheet) { syncSheet(); return; }

            if (own !== null) {
                var planned = own[environment.value] || null;
                sha.value = planned ? planned.prompt_sha256 : '';
                show(planned ? planned.prompt : '', !!planned);
                return;
            }

            var entry = prompts[view] || null;
            var prompt = entry ? entry.prompts[environment.value] : null;
            stage.value = entry ? entry.stage_id : '';
            sha.value = prompt ? prompt.prompt_sha256 : '';
            show(prompt ? prompt.prompt : 'Chưa có prompt do AI viết cho góc này — bấm "AI viết prompt".', !!prompt);

            var conflictRows = entry && entry.conflicts ? entry.conflicts : [];
            lines(pick('conflicts'), conflictRows, '');
            lines(pick('warnings'), entry && entry.warnings ? entry.warnings : [], 'Cảnh báo (vẫn cho render): ');
            pick('blocked').hidden = conflictRows.length === 0;
        }

        function syncQuality() {
            Array.from(quality.options).forEach(function (option) {
                var models = (option.getAttribute('data-models') || '').split(' ');
                option.disabled = option.value !== '' && model.value !== '' && models.indexOf(model.value) === -1;
            });
            if (quality.selectedOptions[0] && quality.selectedOptions[0].disabled) {
                quality.value = '';
            }
        }

        environment.addEventListener('change', sync);
        if (sheet) { pick('sheet-state').addEventListener('change', sync); }
        model.addEventListener('change', syncQuality);
        syncQuality();
        sync();
    });
})();
</script>
@endsection
