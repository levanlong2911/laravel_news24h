@extends('layouts.base', ['title' => 'Anchor Creation'])
@section('title', 'Anchor Creation')

@section('css')
<link rel="stylesheet" href="{{ asset('assets/css/video-producer.css') }}">
@endsection

@section('content')
@php
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

    <div class="va-title">
        <h2>A. ASSET CREATION WORKFLOW</h2>
        <span>(Tạo Asset Nền tảng: Ảnh neo &amp; Ảnh tham chiếu)</span>
    </div>

    <div class="va-insp">
        <div class="va-insp-head">
            <span class="ico">✨</span>
            <div class="txt">
                <b>Inspiration</b> <em>(Ý tưởng nội dung)</em>
                <p>Phân tích bài viết để rút ra thông tin quan trọng và ý tưởng hình ảnh</p>
            </div>
            @if($brief['running'])
                <button class="vp-btn" disabled>Đang phân tích…</button>
            @elseif($brief['stuck'])
                <form method="POST" action="{{ route('video-projects.inspiration-reset', $project->id) }}">
                    @csrf
                    <button class="vp-btn dg">Reset lượt bị kẹt</button>
                </form>
            @elseif($brief['can_run'])
                <form method="POST" action="{{ route('video-projects.inspiration', $project->id) }}"
                      id="inspirationForm" data-modal="confirmInspiration" onsubmit="return vpLockForm(this)">
                    @csrf
                </form>
                <button type="button" class="vp-btn pri" data-toggle="modal" data-target="#confirmInspiration"
                        data-busy="Đang phân tích…">
                    {{ $brief['analysed'] ? 'Phân tích lại (bài đã đổi)' : 'Analysis' }}
                </button>
                @include('modal.confirm_action', [
                    'id' => 'confirmInspiration',
                    'form' => 'inspirationForm',
                    'content' => 'Gọi Claude Haiku phân tích bài viết — tác vụ này tính tiền.',
                    'detail' => 'Lượt gần nhất tốn $0.009209.',
                ])
            @else
                <button class="vp-btn" disabled title="Bài viết không đổi — kết quả cũ vẫn đúng">
Has Analyzed</button>
            @endif
        </div>

        <div class="va-insp-grid">
            <div>
                <div class="va-lbl" style="margin-top:0">NGUỒN BÀI VIẾT</div>
                <div class="va-src">
                    @if($project->article === null)
                        {{-- Dự án do Python đẩy về không đi qua bài viết nào. --}}
                        <div class="t">Không gắn với bài viết nào</div>
                        <div class="d">Dự án được tạo trực tiếp, không qua nút 🎬</div>
                    @else
                        <div class="t">{{ $project->article->title }}</div>
                        @if($project->article->source_url)
                            <a class="u" href="{{ $project->article->source_url }}" target="_blank" rel="noopener">{{ \Illuminate\Support\Str::limit($project->article->source_url, 70) }}</a>
                        @endif
                        <div class="d">
                            @if($project->article->source_name){{ $project->article->source_name }} ·@endif
                            Ngày tạo dự án: {{ $project->created_at?->format('d/m/Y H:i') ?? '—' }}
                        </div>
                    @endif
                </div>
            </div>

            <div>
                <div class="va-lbl" style="margin-top:0">TÓM TẮT Ý TƯỞNG</div>
                <div class="va-brief">
                    @if($brief['error'])
                        <div class="line"><span class="v" style="color:var(--vp-red)">{{ $brief['error'] }}</span></div>
                    @endif
                    @if(! $brief['analysed'])
                        <div class="line">
                            <span class="v">Chưa phân tích</span>
                        </div>
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

            <div class="va-hints">
                <div class="h"><span>Gợi ý hình ảnh chính</span><span class="grow"></span><span>⌃</span></div>
                <div class="chips">
                    @forelse($brief['patterns'] as $pattern)
                        <span class="chip">{{ $pattern }}</span>
                    @empty
                        <span class="none">Chưa có</span>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    <div class="va-grid">

        <div class="vp-panel">
            <div class="va-head">
                <span class="n">1</span>
                <b>Prompt Anchor</b>
                <em>(Viết prompt ảnh neo)</em>
                <span class="grow"></span>
                @if(! $brief['analysed'])
                    <button class="vp-btn sm" disabled title="Cần phân tích bài viết trước">Creat Prompt</button>
                @elseif($concept['running'])
                    <button class="vp-btn sm" disabled>Go to creat prompt...</button>
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
                            data-busy="Đang dựng…">
                        {{ $concept['analysed'] ? 'Creat Prompt news' : 'Creat Prompt' }}
                    </button>
                    @include('modal.confirm_action', [
                        'id' => 'confirmConcept',
                        'form' => 'conceptForm',
                        'content' => 'Gọi Claude Sonnet dựng concept ảnh neo — tác vụ này tính tiền.',
                        'detail' => 'Lượt gần nhất tốn $0.027915.',
                    ])
                @else
                    <button class="vp-btn sm" disabled title="Brief không đổi — concept cũ vẫn đúng">Prompt</button>
                @endif
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

            <div class="vp-tabs">
                <button class="vp-tab on" data-t="a-main">Anchor Prompt</button>
                <button class="vp-tab" data-t="a-neg">Negative Prompt</button>
            </div>

            <div class="va-body">
                <div class="va-lbl">ANCHOR PROMPT
                    @if($concept['error'])
                        <span style="color:var(--vp-red);font-weight:400">{{ $concept['error'] }}</span>
                    @elseif($compiledPrompt === null)
                        <span style="color:var(--vp-red);font-weight:400">{{ $compileReason }}</span>
                    @else
                        <span>(do Python biên dịch — đúng chuỗi sẽ gửi gpt-image-2)</span>
                    @endif
                    {{-- <span>@if($prompt === null)(chưa dựng được — {{ $reason }})@else(Ảnh neo / Canonical)@endif</span> --}}
                </div>
                <textarea class="va-ta" data-v="a-main">{{ $compiledPrompt ?? '' }}</textarea>
                <div class="va-lbl" data-v="a-neg" hidden style="color:var(--vp-amber-fg);font-weight:400">
                    Không được gửi — gpt-image-2 không nhận tham số phủ định.
                    Nội dung tránh né nằm trong khối <b>AVOID</b> của prompt đã biên dịch.
                </div>
                <textarea class="va-ta" data-v="a-neg" hidden>mast, radar dome, helipad, flags, crew, tender, water, sea, sky, dock, crane, scaffolding, text, watermark, logo</textarea>
                <div class="va-count"><span data-c="a">0</span>/6000</div>
            </div>

            <div class="va-lbl" style="padding-left:14px">MODEL &amp; SETTINGS</div>
            <form method="POST" action="{{ route('video-projects.anchor-image', $project->id) }}"
                  id="anchorImageForm" data-modal="confirmAnchorImage" onsubmit="return vpLockForm(this)">
                @csrf
            <div class="va-set">
                <div class="va-field">
                    <label>Model</label>
                    <select class="ctl" name="model" required>
                        @foreach(\App\Enums\ImageModel::cases() as $m)
                            <option value="{{ $m->value }}"
                                    @selected(old('model', \App\Enums\ImageModel::GPT_IMAGE_2->value) === $m->value)>{{ $m->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="va-field">
                    <label>Quality</label>
                    <select class="ctl" name="quality" required>
                        @foreach(\App\Enums\ImageQuality::cases() as $q)
                            <option value="{{ $q->value }}" title="{{ $q->hint() }}"
                                    @selected(old('quality', \App\Enums\ImageQuality::MEDIUM->value) === $q->value)>{{ $q->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="va-field">
                    <label>Resolution</label>
                    <select class="ctl" name="resolution" required>
                        @foreach(\App\Enums\ImageResolution::cases() as $r)
                            <option value="{{ $r->value }}"
                                    @selected(old('resolution', \App\Enums\ImageResolution::PORTRAIT->value) === $r->value)>{{ $r->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="va-field">
                    <label>Variations</label>
                    <select class="ctl" name="variations" required>
                        @foreach(\App\Enums\ImageVariations::cases() as $v)
                            <option value="{{ $v->value }}"
                                    @selected((int) old('variations', \App\Enums\ImageVariations::TWO->value) === $v->value)>{{ $v->label() }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="va-adv">Advanced ⌄</div>
            </form>

            <div class="va-foot">
                @if($compiledPrompt === null)
                    <button class="vp-btn pri" disabled title="Chưa có anchor prompt">Generate Anchor →</button>
                @else
                    <button type="button" class="vp-btn pri" data-toggle="modal" data-target="#confirmAnchorImage"
                            data-busy="Đang render… (khoảng 20 giây)">Generate Anchor →</button>
                    @include('modal.confirm_action', [
                        'id' => 'confirmAnchorImage',
                        'form' => 'anchorImageForm',
                        'content' => 'Gọi gpt-image-2 render ngay với prompt và thiết lập đang chọn — TÁC VỤ NÀY TÍNH TIỀN.',
                        'detail' => 'Ước lượng theo thiết lập đang chọn. Trang sẽ đứng đợi tới khi có ảnh.',
                    ])
                @endif
            </div>
        </div>

        <div class="vp-panel">
            <div class="va-head">
                <span class="n">2</span>
                <b>Render Anchor</b>
                <em>(Render &amp; duyệt ảnh neo)</em>
            </div>

            @forelse($anchorCells as $cell)
                <div class="va-fields one">
                    <div class="va-field">
                        <label>Asset Name</label>
                        <div class="ctl">
                            <span>{{ $cell['image_code'] }}</span>
                            <span class="{{ $cell['has_failed'] ? 'dg' : 'ok' }}">{{ $cell['status_label'] }}</span>
                        </div>
                    </div>
                </div>

                @if($cell['render_error'])
                    <div class="alert alert-danger mx-3">{{ $cell['render_error'] }}</div>
                @endif

                <div class="va-lbl" style="padding-left:14px">
                    {{ $cell['variations'] }} ảnh &middot; {{ $cell['quality'] }} &middot; {{ $cell['size'] }}
                    <span>
                        Ước lượng ${{ number_format($cell['cost_unit'], 3) }}
                        × {{ $cell['variations'] }} = ${{ number_format($cell['cost_estimate'], 3) }}
                        @if($cell['cost_recorded'] > 0)
                            &middot; sổ cái ghi ${{ number_format($cell['cost_recorded'], 4) }}
                        @endif
                    </span>
                </div>

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

                @if($cell['candidates'] !== [])
                    <div class="va-lbl" style="padding-left:14px">ANCHOR PREVIEW <span>(Ứng viên lựa chọn)</span></div>
                    <div class="va-cands">
                        @foreach($cell['candidates'] as $index => $candidate)
                            <div class="va-cand">
                                <div class="cap">CANDIDATE {{ $index + 1 }}</div>
                                <img src="{{ asset($candidate['url']) }}" alt="Candidate {{ $index + 1 }}">
                                <div class="meta">
                                    {{ $cell['quality'] }} &nbsp;|&nbsp; {{ $candidate['width'] }}×{{ $candidate['height'] }}<br>
                                    {{ $candidate['created_at']?->format('Y-m-d H:i:s') }}<br>
                                    sha {{ $candidate['sha'] }}
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            @empty
                <div class="va-lbl" style="padding-left:14px">
                    Chưa có ô nào — dựng prompt rồi bấm <b>Generate Anchor</b> ở khối 1.
                </div>
            @endforelse

            <div class="va-lbl" style="padding-left:14px">QA CHECKLIST
                <span>(chưa nối — sẽ làm ở bước duyệt ảnh)</span>
            </div>

            <div class="va-lbl" style="padding-left:14px">REFERENCE SUMMARY
                <span>(chưa nối — màn ảnh tham chiếu chưa làm)</span>
            </div>

            <div class="va-foot">
                <button class="vp-btn" disabled title="Not connected yet">Regenerate</button>
                <button class="vp-btn" disabled title="Not connected yet">⤓ Download</button>
                <button class="vp-btn ok" disabled title="Approval is step 3.4">✓ Approve as Canonical Anchor</button>
                {{-- <a class="vp-btn ok" href="{{ route('video-session.imageReference', $id-) }}">✓ Approve as Canonical Anchor</a> --}}
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
    if (!form || !box) { return; }

    function sync() {
        var unit = prices[form.querySelector('[name=quality]').value] || 0;
        var count = parseInt(form.querySelector('[name=variations]').value, 10) || 1;
        box.textContent = count + ' ảnh · ' + form.querySelector('[name=quality]').value
            + ' · ' + form.querySelector('[name=resolution]').value
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
    document.querySelectorAll('[form="' + form.id + '"]').forEach(function (btn) { btn.disabled = true; });
    return true;
}

(function () {
    var count = document.querySelector('[data-c="a"]');
    function sync() {
        var vis = document.querySelector('[data-v]:not([hidden])');
        count.textContent = vis ? vis.value.length : 0;
    }
    document.querySelectorAll('[data-t]').forEach(function (tab) {
        tab.addEventListener('click', function () {
            document.querySelectorAll('[data-t]').forEach(function (x) { x.classList.toggle('on', x === tab); });
            document.querySelectorAll('[data-v]').forEach(function (v) { v.hidden = v.dataset.v !== tab.dataset.t; });
            sync();
        });
    });
    document.querySelectorAll('[data-v]').forEach(function (v) { v.addEventListener('input', sync); });
    sync();
})();
</script>
@endsection
