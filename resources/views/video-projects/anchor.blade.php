@extends('layouts.base', ['title' => 'Anchor Creation'])
@section('title', 'Anchor Creation')

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
            <h1>Anchor Creation</h1>
            <p>Tạo identity anchor cho video project</p>
        </div>
        <a class="vp-btn" href="{{ route('video-projects.index') }}">← Quay lại dự án</a>
    </div>

    <div class="va-grid">

        <div class="va-col">

            <div class="vp-panel">
                <div class="va-head">
                    <span class="n">1</span>
                    <b>ARTICLE</b>
                    <span class="grow"></span>
                    <span class="va-tag {{ $project->article ? 'ok' : '' }}">{{ $project->article ? 'Đã thu thập' : 'Chưa gắn bài' }}</span>
                </div>
                <div class="va-art">
                    @if($project->article === null)
                        <div class="t">Không gắn với bài viết nào</div>
                    @else
                        <div class="t">{{ $project->article->title }}</div>
                        @if($project->article->source_url)
                            <a class="u" href="{{ $project->article->source_url }}" target="_blank" rel="noopener">{{ \Illuminate\Support\Str::limit($project->article->source_url, 60) }} ↗</a>
                        @endif
                        <div class="d">Ngày tạo dự án: {{ $project->created_at?->format('d/m/Y H:i') ?? '—' }}</div>
                    @endif
                </div>
            </div>

            <div class="vp-panel">
                <div class="va-head">
                    <span class="n">2</span>
                    <b>HAIKU INSPIRATION</b>
                    <span class="grow"></span>
                    @if($brief['analysed'])<span class="va-tag blue">Đã phân tích</span>@endif
                    @if($brief['running'])
                        <button class="vp-btn sm" disabled>Đang phân tích…</button>
                    @elseif($brief['stuck'])
                        <form method="POST" action="{{ route('video-projects.inspiration-reset', $project->id) }}">
                            @csrf
                            <button class="vp-btn sm dg">Reset lượt bị kẹt</button>
                        </form>
                    @elseif($brief['can_run'])
                        <form method="POST" action="{{ route('video-projects.inspiration', $project->id) }}"
                              id="inspirationForm" data-modal="confirmInspiration" onsubmit="return vpLockForm(this)">
                            @csrf
                        </form>
                        <button type="button" class="vp-btn sm pri" data-toggle="modal" data-target="#confirmInspiration"
                                data-busy="Đang phân tích…">{{ $brief['analysed'] ? 'Phân tích lại' : 'Analysis' }}</button>
                        @include('modal.confirm_action', [
                            'id' => 'confirmInspiration',
                            'form' => 'inspirationForm',
                            'content' => 'Gọi Claude Haiku phân tích bài viết — tác vụ này tính tiền.',
                        ])
                    @endif
                </div>

                <div class="va-insp-grid">
                    <div>
                        <div class="va-lbl" style="margin-top:0">Tóm tắt ý tưởng (Haiku)</div>
                        <div class="va-brief">
                            @if($brief['error'])
                                <div class="line"><span class="v" style="color:var(--vp-red)">{{ $brief['error'] }}</span></div>
                            @elseif(! $brief['analysed'])
                                <div class="line"><span class="v">Chưa phân tích</span></div>
                            @else
                                <div class="line">
                                    <span class="tick">✓</span>
                                    <span class="k">Chủ đề chính</span>
                                    <span class="v">{{ $brief['focus'] }}</span>
                                </div>
                                @foreach($brief['insights'] as $insight)
                                    <div class="line">
                                        <span class="tick">✓</span>
                                        <span class="k">{{ str_replace('_', ' ', $insight['aspect']) }}</span>
                                        <span class="v">{{ $insight['summary'] }}</span>
                                    </div>
                                @endforeach
                            @endif
                        </div>
                    </div>

                </div>
            </div>

            <div class="vp-panel">
                <div class="va-head">
                    <span class="n">3</span>
                    <b>ANCHOR PROMPT</b>
                    <span class="grow"></span>
                    @if($compiledPrompt !== null)<span class="va-tag ok">Đã có prompt</span>@endif
                    @if(! $brief['analysed'])
                        <button class="vp-btn sm" disabled title="Cần brief Haiku trước">Creat Prompt</button>
                    @elseif($anchorPrompt['running'])
                        <button class="vp-btn sm" disabled>Đang viết…</button>
                        <form method="POST" action="{{ route('video-projects.concept-reset', $project->id) }}">
                            @csrf
                            <button class="vp-btn sm dg">Reset lượt bị kẹt</button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('video-projects.concept', $project->id) }}"
                              id="conceptForm" data-modal="confirmConcept" onsubmit="return vpLockForm(this)">
                            @csrf
                        </form>
                        <button type="button" class="vp-btn sm pri" data-toggle="modal" data-target="#confirmConcept"
                                data-busy="Đang viết…">{{ $compiledPrompt === null ? 'Creat Prompt' : 'Viết lại prompt' }}</button>
                        @include('modal.confirm_action', [
                            'id' => 'confirmConcept',
                            'form' => 'conceptForm',
                            'content' => 'Gọi gpt-5.6-terra (medium) viết prompt ảnh từ brief Haiku — tác vụ này tính tiền.',
                            'detail' => 'Cùng brief + cùng thiết lập thì dùng lại bản đã lưu, không gọi lại model.',
                        ])
                    @endif
                </div>
                <div class="va-body">
                    @if($anchorPrompt['error'])
                    <div class="va-lbl" style="color:var(--vp-red);font-weight:400">{{ $anchorPrompt['error'] }}</div>
                    @elseif($compiledPrompt === null)
                    <div class="va-lbl" style="color:var(--vp-red);font-weight:400">{{ $compileReason }}</div>
                    @else
                    <div class="va-lbl" style="font-weight:400;color:var(--vp-dim)">
                        Prompt đã lưu &middot; <b>{{ $selectedStage->label() }}</b>
                        &middot; viết bởi <b>{{ $previewPromptVersion ?? '—' }}</b>
                    </div>
                    @endif

                    <textarea class="va-ta" data-v="a-main" readonly>{{ $compiledPrompt ?? '' }}</textarea>

                    <div class="va-count"><span data-c="a">0</span> ký tự</div>

                    <form method="POST" action="{{ route('video-projects.anchor-image', $project->id) }}"
                          id="anchorImageForm" data-modal="confirmAnchorImage"
                          onsubmit="return vpLockForm(this)" hidden>
                        @csrf
                        <input type="hidden" name="prompt_sha256" value="{{ $compiledPromptHash ?? '' }}">
                    </form>

                    <div class="va-fields">
                    <div class="va-field">
                        <label>Asset Group</label>
                        <div class="ctl">Subject Identity — <code>identity_anchor</code></div>
                    </div>
                    <div class="va-field">
                        <label>Asset Name <em>(mã dự kiến)</em></label>
                        <div class="ctl"><span>{{ $nextImageCode }}</span><span class="cnt">{{ strlen($nextImageCode) }}/100</span></div>
                    </div>
                        <div class="va-field">
                            <label>Size</label>
                            <select class="ctl" name="size" form="anchorImageForm" required @disabled($compiledPrompt === null)>
                                <option value="" @selected($selectedSize === null)>Choose size</option>
                                @foreach(\App\Enums\ImageSize::cases() as $r)
                                    <option value="{{ $r->value }}"
                                            @selected($selectedSize?->value === $r->value)>{{ $r->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="va-field">
                            <label>Model</label>
                            <select class="ctl" name="model" form="anchorImageForm" required @disabled($compiledPrompt === null)>
                                <option value="" @selected($selectedModel === null)>Choose model</option>
                                @foreach(\App\Enums\ImageModel::cases() as $m)
                                    <option value="{{ $m->value }}"
                                            @selected($selectedModel?->value === $m->value)>{{ $m->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="va-field">
                            <label>Quality</label>
                            <select class="ctl" name="quality" form="anchorImageForm" required @disabled($compiledPrompt === null)>
                                <option value="" @selected($selectedQuality === null)>Choose quality</option>
                                @foreach(\App\Enums\ImageQuality::cases() as $q)
                                    <option value="{{ $q->value }}" title="{{ $q->hint() }}"
                                            @selected($selectedQuality?->value === $q->value)>{{ $q->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="va-field">
                            <label>Variations</label>
                            <select class="ctl" name="variations" form="anchorImageForm" required @disabled($compiledPrompt === null)>
                                @foreach(\App\Enums\ImageVariations::cases() as $v)
                                    <option value="{{ $v->value }}"
                                            @selected(($selectedVariations?->value ?? 1) === $v->value)>{{ $v->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="va-foot">
                        <button type="button" id="generateAnchorButton" class="vp-btn pri"
                                data-toggle="modal" data-target="#confirmAnchorImage"
                                data-busy="Đang render…">Render Image</button>
                    </div>
                    @include('modal.confirm_action', [
                        'id' => 'confirmAnchorImage',
                        'form' => 'anchorImageForm',
                        'content' => 'Gửi prompt này cho gpt-image-2 render — TÁC VỤ NÀY TÍNH TIỀN.',
                        'detail' => 'Đang tính…',
                    ])

                </div>
            </div>

        </div>

        <div class="va-col">
            <div class="vp-panel">
                <div class="va-head">
                    <b>CANDIDATE IMAGES</b>
                    <span class="grow"></span>
                    <em>Chọn ảnh tốt nhất làm anchor</em>
                </div>

                @php
                    $candidateCards = collect($anchorCells)
                        ->flatMap(fn ($cell) => collect($cell['candidates'])->map(fn ($candidate) => [
                            'cell' => $cell,
                            'candidate' => $candidate,
                        ]))
                        ->values();
                @endphp

                @forelse($anchorCells as $cell)
                    @if($cell['candidates'] === [])
                        <div class="va-cell">
                            <span class="code">{{ $cell['image_code'] }}</span>
                            <span class="va-tag {{ $cell['status_tone'] }}">{{ $cell['status_label'] }}</span>
                            <span class="grow"></span>
                            <span class="d">{{ $cell['variations'] }} ảnh &middot; {{ $cell['quality'] }} &middot; {{ $cell['size'] }}</span>
                        </div>
                    @endif

                    @if($cell['render_error'])
                        <div class="alert alert-danger mx-3">{{ $cell['render_error'] }}</div>
                    @endif

                    @if($cell['is_live'])
                        <div class="va-lbl" style="padding-left:14px;color:var(--vp-amber-fg);font-weight:400">
                            @if($cell['worker'] === null)
                                Đang chờ worker nhận việc{{ $cell['queued_at'] ? ' — vào hàng đợi '.$cell['queued_at']->diffForHumans() : '' }}.
                                Tải lại trang để xem tiến độ.
                            @elseif(in_array($cell['worker'], ['laravel:direct', 'laravel:canonical'], true))
                                Laravel đang render ngay trong request này — trang sẽ đứng đợi tới khi có ảnh.
                                Nếu request bị ngắt, tải lại trang là kết quả được đối chiếu từ đĩa.
                            @else
                                Worker <b>{{ $cell['worker'] }}</b> đang render. Tải lại trang để xem tiến độ.
                            @endif
                        </div>
                    @elseif($cell['can_render'])
                        <form method="POST" id="renderCell{{ $loop->index }}"
                              action="{{ route('video-projects.design-image-enqueue', [$project->id, $cell['id']]) }}"
                              data-modal="confirmRender{{ $loop->index }}" onsubmit="return vpLockForm(this)">
                            @csrf
                        </form>
                        <div style="padding:0 14px 10px">
                            <button type="button" class="vp-btn pri" data-toggle="modal"
                                    data-target="#confirmRender{{ $loop->index }}" data-busy="Đang xếp hàng…">
                                {{ $cell['has_failed'] ? 'Render lại →' : 'Render Anchor →' }}
                            </button>
                        </div>
                        @include('modal.confirm_action', [
                            'id' => 'confirmRender'.$loop->index,
                            'form' => 'renderCell'.$loop->index,
                            'content' => 'Gửi ô này cho gpt-image-2 render — TÁC VỤ NÀY TÍNH TIỀN.',
                            'detail' => $cell['variations'].' ảnh · '.$cell['quality'].' · '.$cell['size']
                                .' — ước lượng $'.number_format($cell['cost_estimate'], 3),
                        ])
                    @endif
                @empty
                    <div class="va-lbl" style="padding-left:14px;font-weight:400;color:var(--vp-dim)">
                        Chưa có hình ảnh nào - bấm <b>Render Image</b>.
                    </div>
                @endforelse

                <form method="POST" action="{{ route('video-projects.anchor-approve', $project->id) }}"
                      id="approveAnchorForm" onsubmit="return vpLockForm(this)" hidden>
                    @csrf
                </form>

                @if($candidateCards->isNotEmpty())
                    <div class="va-cands">
                        @foreach($candidateCards as $cardIndex => $card)
                            @php
                                $cell = $card['cell'];
                                $candidate = $card['candidate'];
                            @endphp
                            <div class="va-cand">
                                <img src="{{ $candidate['url'] }}" alt="Candidate {{ $cardIndex + 1 }}"
                                     width="{{ $candidate['width'] }}" height="{{ $candidate['height'] }}">
                                <div class="cap">
                                    <span>
                                        <input type="radio" name="artifact_id" form="approveAnchorForm" required
                                               value="{{ $candidate['id'] }}"
                                               @checked($cell['selected_artifact_id'] === $candidate['id'])
                                               @disabled(! $cell['can_approve'])>
                                        CANDIDATE {{ $cardIndex + 1 }}
                                    </span>
                                    @if($cell['selected_artifact_id'] === $candidate['id'])
                                        <b>Preferred</b>
                                    @endif
                                </div>
                                <div class="meta">
                                    <span>{{ $cell['image_code'] }}</span>
                                    <span>Model: {{ $cell['model'] ?? '—' }}</span>
                                    <span>{{ $candidate['width'] }}×{{ $candidate['height'] }}</span>
                                    <span>Created: {{ $candidate['created_at']?->format('Y-m-d H:i:s') ?? '—' }}</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                <div class="va-foot">
                    <button class="vp-btn" disabled title="Chưa nối">Regenerate ⟳</button>
                    <button type="submit" form="approveAnchorForm" class="vp-btn ok"
                            data-busy="Đang duyệt…">✓ Approve as Canonical Anchor 🔒</button>
                </div>
            </div>
        </div>

    </div>
</div>
@endsection

@section('script')
<script src="{{ asset('assets/js/video-producer.js') }}?v={{ filemtime(public_path('assets/js/video-producer.js')) }}"></script>
<script>
(function () {
    var prices = @json(collect(\App\Enums\ImageQuality::cases())
        ->mapWithKeys(fn ($q) => [$q->value => $q->estimatedCostUsd()]));
    var form = document.getElementById('anchorImageForm');
    var box = document.querySelector('#confirmAnchorImage .modal-body p:last-child');
    var button = document.getElementById('generateAnchorButton');
    if (!form || !box) { return; }

    function sync() {
        var missing = ['size', 'model', 'quality', 'variations'].filter(function (name) {
            var el = form.elements[name];
            return !el || el.value === '';
        });
        var promptHash = form.elements.prompt_sha256;
        var hasPrompt = promptHash && promptHash.value.trim() !== '';
        var ready = hasPrompt && missing.length === 0;

        if (button) {
            button.disabled = !ready;
            button.title = !hasPrompt
                ? 'Chưa có anchor prompt'
                : (missing.length ? 'Chưa chọn: ' + missing.join(', ') : '');
        }

        var unit = prices[form.elements.quality.value] || 0;
        var count = parseInt(form.elements.variations.value, 10) || 1;
        box.textContent = count + ' ảnh · ' + form.elements.quality.value
            + ' · ' + form.elements.size.value
            + ' — ước lượng $' + unit.toFixed(3) + ' × ' + count
            + ' = $' + (unit * count).toFixed(3) + '. Trang sẽ đứng đợi tới khi có ảnh.';
    }

    Array.from(form.elements)
        .filter(function (el) { return el.tagName === 'SELECT'; })
        .forEach(function (el) { el.addEventListener('change', sync); });
    sync();
})();

(function () {
    var count = document.querySelector('[data-c="a"]');
    if (!count) { return; }
    function sync() {
        var vis = document.querySelector('[data-v]:not([hidden])');
        count.textContent = vis ? vis.value.length : 0;
    }
    document.querySelectorAll('[data-v]').forEach(function (v) { v.addEventListener('input', sync); });
    sync();
})();
</script>
@endsection
