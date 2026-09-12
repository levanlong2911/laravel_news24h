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
        </div>
    </div>

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
                </form>

                <div class="va-body">
                    <div class="va-fields">
                        <div class="va-field">
                            <label>View</label>
                            <select class="ctl" name="view" id="referenceView" form="referenceForm" required
                                    @disabled($approvedAnchor === null)>
                                @foreach($referenceViewCases as $view)
                                    <option value="{{ $view->value }}">{{ $view->label() }}</option>
                                @endforeach
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
                            <select class="ctl" name="model" form="referenceForm" required
                                    @disabled($approvedAnchor === null)>
                                <option value="">Choose model</option>
                                @foreach(\App\Enums\ImageModel::cases() as $model)
                                    <option value="{{ $model->value }}">{{ $model->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="va-field">
                            <label>Quality</label>
                            <select class="ctl" name="quality" form="referenceForm" required
                                    @disabled($approvedAnchor === null)>
                                <option value="">Choose quality</option>
                                @foreach(\App\Enums\ImageQuality::cases() as $quality)
                                    <option value="{{ $quality->value }}" title="{{ $quality->hint() }}">{{ $quality->label() }}</option>
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

                    <div class="va-lbl" id="referenceMirrorNote" hidden
                         style="color:var(--vp-green-fg);font-weight:400">
                        Góc đối xứng đã có ảnh — lượt này lật ngang tại chỗ, 1 ảnh, $0.000.
                    </div>
                    <div class="va-lbl">PROMPT GỬI ĐI <span>(khối bảo toàn + ghi đè góc máy)</span></div>
                    <textarea class="va-ta" id="referencePrompt" readonly></textarea>
                    <div class="va-count"><span data-c="r">0</span> ký tự</div>

                    <div class="va-foot">
                        <button type="button" id="generateReferenceButton" class="vp-btn pri"
                                data-toggle="modal" data-target="#confirmReference"
                                data-busy="Đang render…" @disabled($approvedAnchor === null)>Generate Reference</button>
                    </div>

                    @include('modal.confirm_action', [
                        'id' => 'confirmReference',
                        'form' => 'referenceForm',
                        'content' => 'Render reference từ canonical anchor đã duyệt.',
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
                    <em>{{ collect($referenceViews)->pluck('view_key')->filter()->unique()->count() }}
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
                                    <span>{{ \App\Video\Reference\ReferenceView::tryFrom((string) $cell['view_key'])?->label() ?? '—' }}
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
                                        &middot; ${{ number_format($cell['cost_recorded'], 3) }}</span>
                                    <span>Created: {{ $candidate['created_at']?->format('Y-m-d H:i:s') ?? '—' }}</span>
                                </div>

                            </div>
                        @endforeach
                    </div>
                @endif

                @forelse($pendingCells as $cell)
                    <div class="va-cell">
                        <span class="code">{{ $cell['image_code'] }}</span>
                        <span class="va-tag blue">{{ \App\Video\Reference\ReferenceView::tryFrom((string) $cell['view_key'])?->label() ?? '—' }}
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
    var preservation = @json($preservationBlock);
    var cameras = @json($cameraOverrides);
    var environments = @json($environmentOverrides);
    var mirrorReady = @json($mirrorReady);
    var view = document.getElementById('referenceView');
    var environment = document.getElementById('referenceEnvironment');
    var variations = document.getElementById('referenceVariations');
    var note = document.getElementById('referenceMirrorNote');
    var box = document.getElementById('referencePrompt');
    var count = document.querySelector('[data-c="r"]');
    if (!view || !environment || !box) { return; }

    function sync() {
        var mirrored = mirrorReady.indexOf(view.value + '|' + environment.value) !== -1;

        if (mirrored) {
            box.value = 'Ảnh này được lật ngang từ góc đối xứng đã render — không gọi model, không tính tiền.';
        } else {
            var blocks = [preservation, cameras[view.value] || ''];
            var extra = environments[environment.value] || '';
            if (extra !== '') { blocks.push(extra); }
            box.value = blocks.join('\n\n');
        }

        if (variations) {
            if (mirrored) { variations.value = '1'; }
            Array.from(variations.options).forEach(function (option) {
                option.disabled = mirrored && option.value !== '1';
            });
        }
        if (note) { note.hidden = !mirrored; }
        if (count) { count.textContent = box.value.length; }
    }

    view.addEventListener('change', sync);
    environment.addEventListener('change', sync);
    sync();
})();
</script>
@endsection
