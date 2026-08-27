@extends('layouts.base', ['title' => 'Anchor Creation'])
@section('title', 'Anchor Creation')

@section('css')
<link rel="stylesheet" href="{{ asset('assets/css/video-producer.css') }}?v={{ filemtime(public_path('assets/css/video-producer.css')) }}">
@endsection

@section('content')
@php
    $steps = [
        ['n' => 1, 'name' => 'Article',          'sub' => 'Nguồn bài viết',     'done' => $project->article !== null],
        ['n' => 2, 'name' => 'Inspiration',      'sub' => 'Ý tưởng nội dung',   'done' => $brief['analysed']],
        ['n' => 3, 'name' => 'Creative Concept', 'sub' => 'Thiết kế khái niệm', 'done' => $concept['analysed']],
        ['n' => 4, 'name' => 'Anchor Setup',     'sub' => 'Thiết lập anchor',   'done' => $compiledPrompt !== null],
        ['n' => 5, 'name' => 'Generate',         'sub' => 'Tạo anchor',         'done' => $anchorCells !== []],
        ['n' => 6, 'name' => 'Approve',          'sub' => 'Duyệt anchor',       'done' => false],
    ];
    $current = 1;
    foreach ($steps as $step) { if ($step['done']) { $current = $step['n'] + 1; } }
@endphp

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

    <div class="va-steps">
        @foreach($steps as $step)
            <div class="va-step {{ $step['done'] ? 'done' : ($step['n'] === $current ? 'now' : '') }}">
                <span class="n">{{ $step['done'] ? '✓' : $step['n'] }}</span>
                <span class="t"><b>{{ $step['name'] }}</b><em>{{ $step['sub'] }}</em></span>
            </div>
            @if(! $loop->last)<span class="va-step-sep">›</span>@endif
        @endforeach
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
                            'detail' => 'Lượt gần nhất tốn $0.009209.',
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
                    <b>SONNET CREATIVE CONCEPT</b>
                    <span class="grow"></span>
                    @if($concept['analysed'])<span class="va-tag ok">Đã đóng băng</span>@endif
                    @if(! $brief['analysed'])
                        <button class="vp-btn sm" disabled title="Cần phân tích bài viết trước">Creat Prompt</button>
                    @elseif($concept['running'])
                        <button class="vp-btn sm" disabled>Đang dựng…</button>
                    @elseif($concept['stuck'])
                        <form method="POST" action="{{ route('video-projects.concept-reset', $project->id) }}">
                            @csrf
                            <button class="vp-btn sm dg">Reset Prompt</button>
                        </form>
                    @elseif($concept['can_run'])
                        <form method="POST" action="{{ route('video-projects.concept', $project->id) }}"
                              id="conceptForm" data-modal="confirmConcept" onsubmit="return vpLockForm(this)">
                            @csrf
                        </form>
                        <button type="button" class="vp-btn sm pri" data-toggle="modal" data-target="#confirmConcept"
                                data-busy="Đang dựng…">{{ $concept['status'] === null ? 'Creat Prompt' : 'Dựng lại concept' }}</button>
                        @include('modal.confirm_action', [
                            'id' => 'confirmConcept',
                            'form' => 'conceptForm',
                            'content' => 'Gọi Claude Sonnet dựng concept ảnh neo — tác vụ này tính tiền.',
                        ])
                    @else
                        <form method="POST" action="{{ route('video-projects.concept-rerun', $project->id) }}"
                              id="conceptRerunForm" data-modal="confirmRerun" onsubmit="return vpLockForm(this)">
                            @csrf
                        </form>
                        <button type="button" class="vp-btn sm" data-toggle="modal" data-target="#confirmRerun"
                                data-busy="Đang dựng…">Dựng lại concept</button>
                        @include('modal.confirm_action', [
                            'id' => 'confirmRerun',
                            'form' => 'conceptRerunForm',
                            'content' => 'The brief has not changed. Sonnet will build a new concept. THIS ACTION COSTS MONEY.',
                            'detail' => 'The previous concept remains available as its own revision. Last concept run cost about $0.027.',
                        ])
                    @endif
                </div>

                <div class="va-concept">
                    @if($concept['error'])
                        <div class="t" style="color:var(--vp-red)">{{ $concept['error'] }}</div>
                    @elseif(! $concept['analysed'])
                        <div class="t">Chưa dựng concept</div>
                    @else
                        <div class="t">{{ $concept['thesis'] }}</div>
                        <div class="ids">
                            @foreach(array_slice($concept['identity'], 0, 6, true) as $key => $value)
                                <span><em>{{ str_replace('_', ' ', $key) }}</em> {{ \Illuminate\Support\Str::limit(is_array($value) ? implode(' · ', $value) : (string) $value, 38) }}</span>
                            @endforeach
                        </div>
                        @if($concept['relationships'] !== [])
                            <div class="rel">
                                @foreach(['governing_line', 'massing_rhythm', 'feature_integration'] as $key)
                                    @if(! empty($concept['relationships'][$key]))
                                        <div><em>{{ str_replace('_', ' ', $key) }}</em>{{ $concept['relationships'][$key] }}</div>
                                    @endif
                                @endforeach
                            </div>
                        @endif
                        @if($concept['features'] !== [])
                            <ul class="feat">
                                @foreach($concept['features'] as $feature)
                                    <li>
                                        {{ $feature['description'] ?? '' }}
                                        @foreach($feature['visible_from'] ?? [] as $viewpoint)
                                            <span>{{ str_replace('_', ' ', $viewpoint) }}</span>
                                        @endforeach
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        @if($concept['provenance_summary'])
                            <div class="prov">
                                {{ $concept['provenance_summary']['total'] }} quyết định
                                &middot; {{ $concept['provenance_summary']['inspired'] }} inspired
                                &middot; {{ $concept['provenance_summary']['invented'] }} invented
                            </div>
                        @endif
                        @if($concept['meta'] !== [])
                            <div class="d">
                                {{ $concept['meta']['model'] }}
                                &middot; {{ $concept['meta']['instruction_version'] }}
                                &middot; {{ number_format((int) $concept['meta']['tokens_in']) }}&rarr;{{ number_format((int) $concept['meta']['tokens_out']) }} token
                                &middot; ${{ number_format((float) $concept['meta']['cost_usd'], 4) }}
                            </div>
                        @endif
                        @if($concept['frozen_at'])
                            <div class="d">🔒 Đóng băng lúc {{ $concept['frozen_at']->format('d/m/Y H:i') }}</div>
                        @endif
                        @if($concept['decisions'] !== [])
                            <details class="va-more">
                                <summary>Quyết định ({{ count($concept['decisions']) }})</summary>
                                <div class="dec">
                                    @foreach($concept['decisions'] as $decision)
                                        <div>
                                            <em>{{ str_replace('_', ' ', (string) ($decision['aspect'] ?? '')) }}</em>
                                            <b class="{{ ($decision['provenance'] ?? '') === 'invented' ? 'iv' : 'in' }}">{{ $decision['provenance'] ?? '' }}</b>
                                            {{ $decision['decision'] ?? '' }}
                                        </div>
                                    @endforeach
                                </div>
                            </details>
                        @endif
                        @if($concept['json'] !== [])
                            <details class="va-more">
                                <summary>Concept JSON</summary>
                                <pre class="cjson">{{ json_encode($concept['json'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                            </details>
                        @endif
                    @endif
                </div>
            </div>

            <div class="vp-panel">
                <div class="va-head">
                    <span class="n">4</span>
                    <b>ANCHOR SETUP</b>
                </div>
                <div class="va-fields">
                    <div class="va-field">
                        <label>Asset Group</label>
                        <div class="ctl">Subject Identity — <code>identity_anchor</code></div>
                    </div>
                    <div class="va-field">
                        <label>Asset Name <em>(mã dự kiến)</em></label>
                        <div class="ctl"><span>{{ $nextImageCode }}</span><span class="cnt">{{ strlen($nextImageCode) }}/100</span></div>
                    </div>
                </div>
            </div>

            <div class="vp-panel">
                <div class="va-head">
                    <span class="n">5</span>
                    <b>PROMPT SETUP</b>
                    <span class="grow"></span>
                    <em>Ba ô này quyết định nội dung prompt</em>
                </div>
                <form method="POST" action="{{ route('video-projects.anchor-compile', $project->id) }}"
                      id="promptSetupForm">
                    @csrf
                    <div class="va-set">
                        <div class="va-field">
                            <label>Stage</label>
                            <select class="ctl" name="stage" required>
                                <option value="" @selected($selectedStage === null)>Choose stage</option>
                                @foreach(\App\Enums\AnchorStage::cases() as $s)
                                    <option value="{{ $s->value }}"
                                            @selected($selectedStage?->value === $s->value)>{{ $s->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="va-field">
                            <label>Viewpoint</label>
                            <select class="ctl" name="viewpoint" required>
                                <option value="" @selected($selectedViewpoint === null)>Choose viewpoint</option>
                                @foreach(\App\Video\Concept\Viewpoint::cases() as $vp)
                                    <option value="{{ $vp->value }}"
                                            @selected($selectedViewpoint?->value === $vp->value)>{{ $viewpointLabels[$vp->value] ?? $vp->value }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="va-field">
                            <label>Size</label>
                            <select class="ctl" name="size" required>
                                <option value="" @selected($selectedSize === null)>Choose size</option>
                                @foreach(\App\Enums\ImageSize::cases() as $r)
                                    <option value="{{ $r->value }}"
                                            @selected($selectedSize?->value === $r->value)>{{ $r->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="va-foot">
                        <button type="submit" class="vp-btn pri">Compile Prompt &lt;/&gt;</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="va-col">

            <div class="vp-panel">
                <div class="va-head">
                    <b>COMPILED ANCHOR PROMPT</b>
                    @if($compiledPrompt !== null)<span class="va-tag ok">✓ VALID</span>@endif
                    <span class="grow"></span>
                </div>

                <form method="POST" action="{{ route('video-projects.anchor-image', $project->id) }}"
                      id="anchorImageForm" data-modal="confirmAnchorImage" data-size="{{ $selectedSize?->value }}"
                      onsubmit="return vpLockForm(this)">
                        @csrf
                        <input type="hidden" name="prompt_sha256" value="{{ $compiledPromptHash ?? '' }}">

                    <div class="va-body">
                        @if($concept['error'])
                        <div class="va-lbl" style="color:var(--vp-red);font-weight:400">{{ $concept['error'] }}</div>
                        @elseif($compiledPrompt === null)
                        <div class="va-lbl" style="color:var(--vp-red);font-weight:400">{{ $compileReason }}</div>
                        @else
                        <div class="va-lbl" style="font-weight:400;color:var(--vp-dim)">
                            Saved compiled preview. Generate Anchor stores a render candidate.
                            &middot; Compiled for:
                            <b>{{ $selectedStage->label() }}</b> &middot;
                            <b>{{ $viewpointLabels[$selectedViewpoint->value] ?? $selectedViewpoint->value }}</b> &middot;
                            <b>{{ $selectedSize->label() }}</b>
                        </div>
                        @endif

                        <textarea class="va-ta" data-v="a-main" readonly>{{ $compiledPrompt ?? '' }}</textarea>

                        <div class="va-count"><span data-c="a">0</span>/12000</div>
                    </div>

                    <div class="va-head" style="border-top:1px solid var(--vp-line)">
                        <b>RENDER SETUP</b>
                        <span class="grow"></span>
                        @if($compiledPrompt === null)
                        <button class="vp-btn pri" disabled title="Chưa có anchor prompt">Generate Anchor ✦</button>
                        @else
                        <button type="button" id="generateAnchorButton" class="vp-btn pri" disabled
                                data-toggle="modal" data-target="#confirmAnchorImage"
                                data-busy="Đang render… (khoảng 20 giây)">Generate Anchor ✦</button>
                        @include('modal.confirm_action', [
                            'id' => 'confirmAnchorImage',
                            'form' => 'anchorImageForm',
                            'content' => 'Gọi gpt-image-2 render ngay với prompt và thiết lập đang chọn — TÁC VỤ NÀY TÍNH TIỀN.',
                            'detail' => 'Ước lượng theo thiết lập đang chọn. Trang sẽ đứng đợi tới khi có ảnh.',
                        ])
                        @endif
                    </div>

                        <div class="va-set">
                        <div class="va-field">
                            <label>Model</label>
                            <select class="ctl" name="model" required @disabled($compiledPrompt === null)>
                                <option value="" @selected($selectedModel === null)>Choose model</option>
                                @foreach(\App\Enums\ImageModel::cases() as $m)
                                    <option value="{{ $m->value }}"
                                            @selected($selectedModel?->value === $m->value)>{{ $m->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="va-field">
                            <label>Quality</label>
                            <select class="ctl" name="quality" required @disabled($compiledPrompt === null)>
                                <option value="" @selected($selectedQuality === null)>Choose quality</option>
                                @foreach(\App\Enums\ImageQuality::cases() as $q)
                                    <option value="{{ $q->value }}" title="{{ $q->hint() }}"
                                            @selected($selectedQuality?->value === $q->value)>{{ $q->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="va-field">
                            <label>Variations</label>
                            <select class="ctl" name="variations" required @disabled($compiledPrompt === null)>
                                <option value="" @selected($selectedVariations === null)>Choose variations</option>
                                @foreach(\App\Enums\ImageVariations::cases() as $v)
                                    <option value="{{ $v->value }}"
                                            @selected($selectedVariations?->value === $v->value)>{{ $v->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </form>
            </div>

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
                            <span class="va-tag {{ $cell['has_failed'] ? 'dg' : 'ok' }}">{{ $cell['status_label'] }}</span>
                            <span class="grow"></span>
                            <span class="d">{{ $cell['variations'] }} ảnh &middot; {{ $cell['quality'] }} &middot; {{ $cell['size'] }}</span>
                        </div>
                    @endif

                    @if($cell['render_error'])
                        <div class="alert alert-danger mx-3">{{ $cell['render_error'] }}</div>
                    @endif

                    @if($cell['is_live'])
                        <div class="va-lbl" style="padding-left:14px;color:var(--vp-amber-fg);font-weight:400">
                            Đang chờ worker nhận việc{{ $cell['queued_at'] ? ' — vào hàng đợi '.$cell['queued_at']->diffForHumans() : '' }}.
                            Tải lại trang để xem tiến độ.
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
                        Chưa có ứng viên nào — biên dịch prompt rồi bấm <b>Generate Anchor</b>.
                    </div>
                @endforelse

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
                                        <input type="radio" disabled title="Chọn ứng viên — chưa nối">
                                        CANDIDATE {{ $cardIndex + 1 }}
                                    </span>
                                    @if($cardIndex === 0)
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
                    <button class="vp-btn ok" disabled title="Chưa nối — lô duyệt anchor">✓ Approve as Canonical Anchor 🔒</button>
                </div>
            </div>
        </div>

    </div>
</div>
@endsection

@section('script')
<script>
(function () {
    var prices = @json(collect(\App\Enums\ImageQuality::cases())
        ->mapWithKeys(fn ($q) => [$q->value => $q->estimatedCostUsd()]));
    var form = document.getElementById('anchorImageForm');
    var box = document.querySelector('#confirmAnchorImage .modal-body p:last-child');
    var button = document.getElementById('generateAnchorButton');
    if (!form || !box) { return; }

    function sync() {
        var missing = ['model', 'quality', 'variations'].filter(function (name) {
            var el = form.querySelector('[name=' + name + ']');
            return !el || el.value === '';
        });
        var promptHash = form.querySelector('[name=prompt_sha256]');
        var hasPrompt = promptHash && promptHash.value.trim() !== '';
        var ready = hasPrompt && missing.length === 0;

        if (button) {
            button.disabled = !ready;
            button.title = !hasPrompt
                ? 'Chưa có anchor prompt'
                : (missing.length ? 'Chưa chọn: ' + missing.join(', ') : '');
        }

        var unit = prices[form.querySelector('[name=quality]').value] || 0;
        var count = parseInt(form.querySelector('[name=variations]').value, 10) || 1;
        box.textContent = count + ' ảnh · ' + form.querySelector('[name=quality]').value
            + ' · ' + (form.dataset.size || '')
            + ' — ước lượng $' + unit.toFixed(3) + ' × ' + count
            + ' = $' + (unit * count).toFixed(3) + '. Trang sẽ đứng đợi tới khi có ảnh.';
    }

    form.querySelectorAll('select').forEach(function (el) { el.addEventListener('change', sync); });
    sync();
})();

function vpLockForm(form) {
    var trigger = document.querySelector('[data-target="#' + form.dataset.modal + '"]');
    if (trigger) {
        trigger.disabled = true;
        if (trigger.dataset.busy) { trigger.textContent = trigger.dataset.busy; }
    }
    window.setTimeout(function () {
        document.querySelectorAll('[form="' + form.id + '"]').forEach(function (btn) { btn.disabled = true; });
    }, 0);
    return true;
}

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
