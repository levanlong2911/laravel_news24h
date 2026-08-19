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

    <div class="va-grid">

        <div class="vp-panel">
            <div class="va-head">
                <span class="n">1</span>
                <b>Prompt Anchor</b>
                <em>(Viết prompt ảnh neo)</em>
            </div>

            <div class="va-fields">
                <div class="va-field">
                    <label>Asset Group</label>
                    <div class="ctl sel">Subject Identity (Tàu chính)</div>
                </div>
                <div class="va-field">
                    <label>Asset Name</label>
                    <div class="ctl"><span>master_vessel_anchor_v1</span><span class="cnt">21/100</span></div>
                </div>
            </div>

            <div class="vp-tabs">
                <button class="vp-tab on" data-t="a-main">Anchor Prompt</button>
                <button class="vp-tab" data-t="a-neg">Negative Prompt</button>
            </div>

            <div class="va-body">
                <div class="va-lbl">ANCHOR PROMPT
                    {{-- <span>@if($prompt === null)(chưa dựng được — {{ $reason }})@else(Ảnh neo / Canonical)@endif</span> --}}
                </div>
                <textarea class="va-ta" data-v="a-main">...</textarea>
                <textarea class="va-ta" data-v="a-neg" hidden>mast, radar dome, helipad, flags, crew, tender, water, sea, sky, dock, crane, scaffolding, text, watermark, logo</textarea>
                <div class="va-count"><span data-c="a">0</span>/4000</div>
            </div>

            <div class="va-lbl" style="padding-left:14px">MODEL &amp; SETTINGS</div>
            <div class="va-set">
                <div class="va-field"><label>Model</label><div class="ctl sel">GPT Image 2</div></div>
                <div class="va-field"><label>Quality</label><div class="ctl sel">High</div></div>
                <div class="va-field"><label>Resolution</label><div class="ctl sel">1024 × 1536 (9:16)</div></div>
                <div class="va-field"><label>Seed</label><div class="ctl sel">Auto</div></div>
                <div class="va-field"><label>Variations</label><div class="ctl sel">2</div></div>
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
                        Model: GPT Image 2 &nbsp;|&nbsp; 1024×1536 (9:16)<br>
                        Seed: 12345 &nbsp;|&nbsp; Created: 2026-08-15 10:21:11<br>
                        Cost: $0.17
                    </div>
                </div>
                <div class="va-cand">
                    <div class="cap">CANDIDATE 2</div>
                    <img src="{{ $candidate2 }}" alt="Candidate 2">
                    <div class="meta">
                        Model: GPT Image 2 &nbsp;|&nbsp; 1024×1536 (9:16)<br>
                        Seed: 12345 &nbsp;|&nbsp; Created: 2026-08-15 10:21:11<br>
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
