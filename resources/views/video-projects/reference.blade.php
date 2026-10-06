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

    <div class="va-page">
        <div>
            <h1>Reference Views</h1>
            <p>Render các góc tham chiếu từ canonical anchor đã duyệt</p>
        </div>
        <div style="display:flex;gap:8px">
            <a class="vp-btn" href="{{ route('video-projects.anchor', $id) }}">← Ảnh neo</a>
            <a class="vp-btn" href="{{ route('video-projects.environment', $id) }}">Environment Library →</a>
            <a class="vp-btn" href="{{ route('video-projects.scene', $id) }}">Scenes → Clip</a>
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

    <div class="va-grid">

        <div class="va-col">

            <div class="vp-panel">
                <div class="va-head">
                    <span class="n">1</span>
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
                        <img src="{{ $approvedAnchor->artifact->storage_disk === 'public'
                            ? $approvedAnchor->artifact->storage_path
                            : route('video-artifacts.show', $approvedAnchor->artifact->id) }}"
                             alt="{{ $approvedAnchor->image_code }}"
                             style="max-width:100%;display:block;margin-bottom:10px">
                        <div class="va-lbl" style="font-weight:400;color:var(--vp-dim)">
                            {{ $approvedAnchor->image_code }}
                        </div>
                        <div class="va-lbl" style="font-weight:400;color:var(--vp-dim)">
                            Artifact: {{ $approvedAnchor->artifact->id }}
                        </div>
                        <div class="va-lbl" style="font-weight:400;color:var(--vp-dim)">
                            SHA256: {{ $approvedAnchor->artifact->sha256 }}
                        </div>
                        @php
                            $source = $referenceSource ?? [];
                        @endphp
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
                    @else
                        <div class="va-lbl" style="color:var(--vp-red);font-weight:400">
                            {{ __('messages.no_approved_anchor') }}
                        </div>
                    @endif
                </div>
            </div>

            <div class="vp-panel">
                <div class="va-head">
                    <span class="n">2</span>
                    <b>GENERATE REFERENCE VIEW</b>
                </div>

                <form method="POST" action="{{ route('video-projects.reference', $id) }}"
                      id="referenceForm" data-modal="confirmReference"
                      onsubmit="return vpLockForm(this)" hidden>
                    @csrf
                    <input type="hidden" name="reference_prompt_stage_id" id="referencePromptStage" value="">
                    <input type="hidden" name="prompt_sha256" id="referencePromptSha" value="">
                </form>

                <form method="POST" action="{{ route('video-projects.reference-prompt', $id) }}"
                      id="referencePromptForm" data-modal="confirmReferencePrompt"
                      onsubmit="return vpLockForm(this)" hidden>
                    @csrf
                    <input type="hidden" name="view" id="referencePromptView" value="">
                </form>

                <div class="va-body">
                    <div class="va-fields">
                        <div class="va-field">
                            <label>View</label>
                            <select class="ctl" name="view" id="referenceView" form="referenceForm" required
                                    @disabled($approvedAnchor === null)>
                                @foreach($referenceViewCases as $view)
                                    <option value="{{ $view->value }}" @selected(old('view') === $view->value)>{{ $view->displayLabel() }}</option>
                                @endforeach
                                <option value="" disabled>Tạo các ảnh cận cảnh chi tiết (chưa hỗ trợ)</option>
                                <option value="" disabled>Tạo bộ ảnh tham chiếu từ ảnh hiện tại (chưa hỗ trợ)</option>
                            </select>
                        </div>
                        <div class="va-field">
                            <label>Environment</label>
                            <select class="ctl" name="environment" id="referenceEnvironment" form="referenceForm" required
                                    @disabled($approvedAnchor === null)>
                                @foreach($referenceEnvironmentCases as $environment)
                                    <option value="{{ $environment->value }}">{{ $environment->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="va-field">
                            <label>Model</label>
                            <select class="ctl" name="model" id="referenceModel" form="referenceForm" required
                                    @disabled($approvedAnchor === null)>
                                <option value="">Choose model</option>
                                @foreach(\App\Enums\ImageModel::cases() as $model)
                                    <option value="{{ $model->value }}">{{ $model->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="va-field">
                            <label>Quality</label>
                            <select class="ctl" name="quality" id="referenceQuality" form="referenceForm" required
                                    @disabled($approvedAnchor === null)>
                                <option value="">Choose quality</option>
                                @foreach(\App\Enums\ImageQuality::cases() as $quality)
                                    <option value="{{ $quality->value }}" title="{{ $quality->hint() }}"
                                            data-models="{{ collect(\App\Enums\ImageModel::cases())->filter(fn ($m) => $m->supports($quality))->map(fn ($m) => $m->value)->implode(' ') }}">{{ $quality->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="va-field">
                            <label>Size</label>
                            <select class="ctl" name="size" form="referenceForm" required
                                    @disabled($approvedAnchor === null)>
                                <option value="">Choose size</option>
                                @foreach(\App\Enums\ImageSize::cases() as $size)
                                    <option value="{{ $size->value }}">{{ $size->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="va-field">
                            <label>Variations</label>
                            <select class="ctl" name="variations" id="referenceVariations" form="referenceForm" required
                                    @disabled($approvedAnchor === null)>
                                <option value="">Choose variations</option>
                                @foreach(\App\Enums\ImageVariations::cases() as $variation)
                                    <option value="{{ $variation->value }}">{{ $variation->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="va-lbl">PROMPT GỬI ĐI <span>(AI viết VIEW GEOMETRY · mục tiêu edit + giữ nhận dạng + góc máy + môi trường + trạng thái do PHP ghép)</span></div>
                    <textarea class="va-ta" id="referencePrompt" readonly></textarea>
                    <div class="va-count"><span data-c="r">0</span> ký tự</div>

                    <div class="va-lbl" id="referenceBlocked" hidden style="color:var(--vp-red);font-weight:400">
                        AI báo nguồn thiết kế hoặc ảnh anchor còn mâu thuẫn hoặc thiếu dữ kiện cho góc này (vẫn cho render):
                    </div>
                    <div id="referenceConflicts"></div>
                    <div id="referenceWarnings"></div>

                    <div class="va-foot">
                        <button type="button" class="vp-btn" data-toggle="modal" data-target="#confirmReferencePrompt"
                                data-busy="Đang viết…" @disabled($approvedAnchor === null)>AI viết prompt</button>
                        <button type="button" id="generateReferenceButton" class="vp-btn pri"
                                data-toggle="modal" data-target="#confirmReference"
                                data-busy="Đang render…" @disabled($approvedAnchor === null)>Generate Reference</button>
                    </div>

                    @include('modal.confirm_action', [
                        'id' => 'confirmReferencePrompt',
                        'form' => 'referencePromptForm',
                        'content' => 'Gửi ảnh anchor và gói thiết kế của góc đang chọn cho model viết VIEW GEOMETRY — TÁC VỤ NÀY TÍNH TIỀN.',
                        'detail' => 'Ảnh, nguồn và cấu hình không đổi thì dùng lại prompt đã viết, không gọi model.',
                    ])

                    @include('modal.confirm_action', [
                        'id' => 'confirmReference',
                        'form' => 'referenceForm',
                        'content' => 'Render reference bằng đúng prompt AI đang hiển thị.',
                        'detail' => 'Chi phí được tính theo model, quality và variations.',
                    ])
                </div>
            </div>

        </div>

        <div class="va-col">
            <div class="vp-panel">
                <div class="va-head">
                    <b>REFERENCE VIEWS</b>
                    <span class="grow"></span>
                    <em>{{ collect($referenceViews)->pluck('view_key')->intersect(collect($referenceViewCases)->map(fn ($view) => $view->value))->unique()->count() }}
                        / {{ count($referenceViewCases) }} góc &middot; {{ count($referenceViews) }} ô</em>
                </div>

                @php
                    $referenceCards = collect($referenceViews)
                        ->flatMap(fn ($cell) => collect($cell['candidates'])->map(fn ($candidate) => [
                            'cell' => $cell,
                            'candidate' => $candidate,
                        ]))
                        ->values();
                    $pendingCells = collect($referenceViews)->where('candidates', []);
                @endphp

                @if($referenceCards->isNotEmpty())
                    <div class="va-cands">
                        @foreach($referenceCards as $card)
                            @php
                                $cell = $card['cell'];
                                $candidate = $card['candidate'];
                            @endphp
                            <div class="va-cand">
                                <img src="{{ $candidate['url'] }}" alt="{{ $cell['image_code'] }}"
                                     width="{{ $candidate['width'] }}" height="{{ $candidate['height'] }}">
                                <div class="cap">
                                    <span>{{ \App\Video\Reference\ReferenceView::tryFrom((string) $cell['view_key'])?->displayLabel() ?? '—' }}
                                        &middot; {{ \App\Video\Reference\ReferenceEnvironment::tryFrom((string) $cell['environment'])?->label() ?? '—' }}</span>
                                    <span class="act">
                                        <b class="{{ $cell['status_tone'] }}">{{ $cell['status_label'] }}</b>

                                        @if($cell['can_approve'])
                                            @if($cell['selected_artifact_id'] === $candidate['id'])
                                                <b class="ok">Đang dùng</b>
                                            @else
                                                <form method="POST"
                                                      action="{{ route('video-projects.reference-approve', $id) }}">
                                                    @csrf
                                                    <input type="hidden" name="artifact_id" value="{{ $candidate['id'] }}">
                                                    <button class="vp-btn ok sm">✓ Duyệt</button>
                                                </form>
                                            @endif
                                        @endif
                                    </span>
                                </div>
                                <div class="meta">
                                    <span>{{ $cell['image_code'] }}</span>
                                    <span>{{ $candidate['width'] }}×{{ $candidate['height'] }}</span>
                                    <span>{{ $cell['variations'] }} ảnh &middot; {{ $cell['quality'] }}
                                        @if($cell['cost_recorded_has_ledger'])
                                            &middot; @include('video-projects.partials.cost-recorded', ['cell' => $cell])
                                        @endif</span>
                                    <span>Created: {{ $candidate['created_at']?->format('Y-m-d H:i:s') ?? '—' }}</span>
                                </div>

                            </div>
                        @endforeach
                    </div>
                @endif

                @forelse($pendingCells as $cell)
                    <div class="va-cell">
                        <span class="code">{{ $cell['image_code'] }}</span>
                        <span class="va-tag blue">{{ \App\Video\Reference\ReferenceView::tryFrom((string) $cell['view_key'])?->displayLabel() ?? '—' }}
                            &middot; {{ \App\Video\Reference\ReferenceEnvironment::tryFrom((string) $cell['environment'])?->label() ?? '—' }}</span>
                        <span class="va-tag {{ $cell['status_tone'] }}">{{ $cell['status_label'] }}</span>
                        <span class="grow"></span>
                        <span class="d">{{ $cell['variations'] }} ảnh &middot; {{ $cell['quality'] }} &middot; {{ $cell['size'] }}</span>
                    </div>

                    @if($cell['render_error'])
                        <div class="alert alert-danger mx-3">{{ $cell['render_error'] }}</div>
                    @endif

                    @if($cell['is_live'])
                        <div class="va-lbl" style="padding-left:14px;color:var(--vp-amber-fg);font-weight:400">
                            Đang render — tải lại trang để xem tiến độ.
                        </div>
                    @elseif($cell['can_render'])
                        <form method="POST" id="renderRef{{ $loop->index }}"
                              action="{{ route('video-projects.design-image-enqueue', [$id, $cell['id']]) }}"
                              data-modal="confirmRenderRef{{ $loop->index }}" onsubmit="return vpLockForm(this)">
                            @csrf
                        </form>
                        <div style="padding:0 14px 10px">
                            <button type="button" class="vp-btn pri" data-toggle="modal"
                                    data-target="#confirmRenderRef{{ $loop->index }}" data-busy="Đang render…">
                                {{ $cell['has_failed'] ? 'Render lại →' : 'Render →' }}
                            </button>
                        </div>
                        @include('modal.confirm_action', [
                            'id' => 'confirmRenderRef'.$loop->index,
                            'form' => 'renderRef'.$loop->index,
                            'content' => 'Gửi ô này cho gpt-image-2 render — TÁC VỤ NÀY TÍNH TIỀN.',
                            'detail' => $cell['variations'].' ảnh · '.$cell['quality'].' · '.$cell['size']
                                .' — ước lượng $'.number_format($cell['cost_estimate'], 3),
                        ])
                    @endif
                @empty
                    @if($referenceCards->isEmpty())
                        <div class="va-lbl" style="padding-left:14px;font-weight:400;color:var(--vp-dim)">
                            Chưa có góc tham chiếu nào — chọn view rồi bấm <b>Generate Reference</b>.
                        </div>
                    @endif
                @endforelse
            </div>
        </div>

    </div>
</div>
@endsection

@section('script')
<script src="{{ asset('assets/js/video-producer.js') }}?v={{ filemtime(public_path('assets/js/video-producer.js')) }}"></script>
<script>
(function () {
    var prompts = @json($referencePrompts);
    var view = document.getElementById('referenceView');
    var environment = document.getElementById('referenceEnvironment');
    var box = document.getElementById('referencePrompt');
    var stage = document.getElementById('referencePromptStage');
    var sha = document.getElementById('referencePromptSha');
    var promptView = document.getElementById('referencePromptView');
    var blocked = document.getElementById('referenceBlocked');
    var conflicts = document.getElementById('referenceConflicts');
    var warnings = document.getElementById('referenceWarnings');
    var render = document.getElementById('generateReferenceButton');
    var count = document.querySelector('[data-c="r"]');
    if (!view || !environment || !box) { return; }

    function sync() {
        var entry = prompts[view.value] || null;
        var prompt = entry ? entry.prompts[environment.value] : null;

        box.value = prompt ? prompt.prompt : 'Chưa có prompt do AI viết cho góc này — bấm "AI viết prompt".';
        stage.value = entry ? entry.stage_id : '';
        sha.value = prompt ? prompt.prompt_sha256 : '';
        promptView.value = view.value;

        conflicts.innerHTML = '';
        var conflictRows = entry && entry.conflicts ? entry.conflicts : [];
        conflictRows.forEach(function (line) {
            var item = document.createElement('div');
            item.className = 'va-lbl';
            item.style.fontWeight = '400';
            item.style.color = 'var(--vp-red)';
            item.textContent = line;
            conflicts.appendChild(item);
        });

        warnings.innerHTML = '';
        (entry && entry.warnings ? entry.warnings : []).forEach(function (line) {
            var item = document.createElement('div');
            item.className = 'va-lbl';
            item.style.fontWeight = '400';
            item.style.color = 'var(--vp-red)';
            item.textContent = 'Cảnh báo (vẫn cho render): ' + line;
            warnings.appendChild(item);
        });

        blocked.hidden = conflictRows.length === 0;
        if (render && {{ $approvedAnchor === null ? 'false' : 'true' }}) {
            render.disabled = !prompt;
        }
        if (count) { count.textContent = prompt ? box.value.length : 0; }
    }

    view.addEventListener('change', sync);
    environment.addEventListener('change', sync);
    sync();
})();

(function () {
    var model = document.getElementById('referenceModel');
    var quality = document.getElementById('referenceQuality');
    if (!model || !quality) { return; }

    function sync() {
        Array.from(quality.options).forEach(function (option) {
            var models = (option.getAttribute('data-models') || '').split(' ');
            option.disabled = option.value !== '' && model.value !== '' && models.indexOf(model.value) === -1;
        });
        if (quality.selectedOptions[0] && quality.selectedOptions[0].disabled) {
            quality.value = '';
        }
    }

    model.addEventListener('change', sync);
    sync();
})();
</script>
@endsection
