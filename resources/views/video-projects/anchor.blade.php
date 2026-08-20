@extends('layouts.base', ['title' => 'Anchor Creation'])
@section('title', 'Anchor Creation')

@section('css')
<link rel="stylesheet" href="{{ asset('assets/css/video-producer.css') }}">
@endsection

@section('content')
@php
    $candidate1 = asset('renders/shots/a26f7a87-67b1-40fc-91ac-f9581093d722.jpg');
    $candidate2 = asset('renders/shots/a2718f3f-4265-494f-add0-a96fd35f7d9f.jpg');

    $qaChecklist = [
        'Proportions match (5.8:1)',
        'Deck tiers = 4',
        'Bow vertical & concave facet',
        'Superstructure continuous',
        'No extra elements (mast, radar domes, etc.)',
    ];

    $refSummary = [
        'Environment' => '1 ảnh',
        'Subject Identity' => '3 ảnh',
        'Construction Master' => '0 ảnh',
        'Equipment' => '0 ảnh',
    ];

@endphp

<div class="container-fluid vp">

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
                      onsubmit="return vpConfirmOnce(this)">
                    @csrf
                    <button class="vp-btn pri">
                        {{ $brief['analysed'] ? 'Phân tích lại (bài đã đổi)' : 'Analysis' }}
                    </button>
                </form>
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
                          onsubmit="return vpConfirmConcept(this)">
                        @csrf
                        <button class="vp-btn sm pri">
                            {{ $concept['analysed'] ? 'Creat Prompt news' : 'Creat Prompt' }}
                        </button>
                    </form>
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
                    <label>Asset Name</label>
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
            <div class="va-set">
                <div class="va-field">
                    <label>Model</label>
                    <select class="ctl" name="model">
                        @foreach(\App\Enums\ImageModel::cases() as $m)
                            <option value="{{ $m->value }}">{{ $m->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="va-field">
                    <label>Quality</label>
                    <select class="ctl" name="quality">
                        @foreach(\App\Enums\ImageQuality::cases() as $q)
                            <option value="{{ $q->value }}" title="{{ $q->hint() }}"
                                    @selected($q === \App\Enums\ImageQuality::MEDIUM)>{{ $q->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="va-field">
                    <label>Resolution</label>
                    <select class="ctl" name="resolution">
                        @foreach(\App\Enums\ImageResolution::cases() as $r)
                            <option value="{{ $r->value }}"
                                    @selected($r === \App\Enums\ImageResolution::PORTRAIT)>{{ $r->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="va-field">
                    <label>Variations</label>
                    <select class="ctl" name="variations">
                        @foreach(\App\Enums\ImageVariations::cases() as $v)
                            <option value="{{ $v->value }}"
                                    @selected($v === \App\Enums\ImageVariations::TWO)>{{ $v->label() }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="va-adv">Advanced ⌄</div>

            <div class="va-foot">
                <button class="vp-btn pri">Generate Anchor →</button>
            </div>
        </div>

        <div class="vp-panel">
            <div class="va-head">
                <span class="n">2</span>
                <b>Render Anchor</b>
                <em>(Render &amp; duyệt ảnh neo)</em>
            </div>

            <div class="va-fields one">
                <div class="va-field">
                    <label>Asset Name</label>
                    <div class="ctl"><span>master_vessel_anchor_v1</span><span class="ok">Generated</span></div>
                </div>
            </div>

            <div class="va-lbl" style="padding-left:14px">ANCHOR PREVIEW <span>(Ứng viên lựa chọn)</span></div>
            <div class="va-cands">
                <div class="va-cand pick">
                    <div class="cap">CANDIDATE 1<span class="badge">★ Chọn</span></div>
                    <img src="{{ $candidate1 }}" alt="Candidate 1">
                    <div class="meta">
                        Model: GPT Image 2 &nbsp;|&nbsp; 1024×1536 (2:3)<br>
                        Created: 2026-08-15 10:21:11<br>
                        Cost: $0.17
                    </div>
                </div>
                <div class="va-cand">
                    <div class="cap">CANDIDATE 2</div>
                    <img src="{{ $candidate2 }}" alt="Candidate 2">
                    <div class="meta">
                        Model: GPT Image 2 &nbsp;|&nbsp; 1024×1536 (2:3)<br>
                        Created: 2026-08-15 10:21:11<br>
                        Cost: $0.17
                    </div>
                </div>
            </div>

            <div class="va-lbl" style="padding-left:14px">QA CHECKLIST</div>
            <div class="va-check">
                @foreach($qaChecklist as $item)
                    <div class="line">
                        <span class="tick">✓</span>
                        <span>{{ $item }}</span>
                        <span class="grow"></span>
                        <span class="pass">PASS</span>
                    </div>
                @endforeach
            </div>

            <div class="va-lbl" style="padding-left:14px">REFERENCE SUMMARY</div>
            <div class="va-sum">
                @foreach($refSummary as $name => $count)
                    <div class="line"><span>{{ $name }}</span><span class="grow"></span><b>{{ $count }}</b></div>
                @endforeach
                <div class="line total"><span>Total</span><span class="grow"></span><b>4 ảnh</b></div>
            </div>

            <div class="va-foot">
                <button class="vp-btn">Regenerate</button>
                <button class="vp-btn">⤓ Download</button>
                <a class="vp-btn ok" href="">✓ Approve as Canonical Anchor</a>
                {{-- <a class="vp-btn ok" href="{{ route('video-session.imageReference', $id-) }}">✓ Approve as Canonical Anchor</a> --}}
            </div>
        </div>

    </div>
</div>
@endsection

@section('script')
<script>
function vpConfirmConcept(form) {
    if (!confirm('Gọi Claude Sonnet dựng concept — tốn khoảng $0.05. Tiếp tục?')) { return false; }
    var btn = form.querySelector('button');
    btn.disabled = true;
    btn.textContent = 'Đang dựng…';
    return true;
}

function vpConfirmOnce(form) {
    if (!confirm('Gọi Claude Haiku — tốn tiền đó. Tiếp tục?')) { return false; }
    var btn = form.querySelector('button');
    btn.disabled = true;
    btn.textContent = 'Đang phân tích…';
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
