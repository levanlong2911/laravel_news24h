@extends('layouts.base', ['title' => 'Asset Creation'])
@section('title', 'Asset Creation')

@section('css')
<link rel="stylesheet" href="{{ asset('assets/css/video-producer.css') }}">
@endsection

@section('content')
@php
    $views = [
        ['01 Port Side', 'a26f7a87-67b1-40fc-91ac-f9581093d722.jpg'],
        ['02 Starboard Side', 'a26f7a87-67b1-40fc-91ac-f9581093d722.jpg'],
        ['03 Bow Front', 'a27234ea-7269-4dd1-b3fa-078258c68f3a.jpg'],
        ['04 Stern Rear', 'a26f7a87-67b1-40fc-91ac-f9581093d722.jpg'],
        ['05 Bow 3/4', 'a2718f3f-4265-494f-add0-a96fd35f7d9f.jpg'],
        ['06 Stern 3/4', 'a27234ea-70fe-4601-ba15-4ade478ae71a.jpg'],
        ['07 Top Deck', 'a2718f3f-417a-484a-a4b3-29f3d9979878.jpg'],
        ['08 In Shipyard Hall', 'a27234ea-703e-4fab-81a8-91e7685e55eb.jpg'],
    ];
@endphp

<div class="container-fluid vp">

    <div class="va-grid">

        <div class="vp-panel">
            <div class="va-head">
                <span class="n">3</span>
                <b>Prompt Reference</b>
                <em>(Viết prompt ảnh tham chiếu)</em>
                <span class="grow"></span>
                <a class="vp-btn sm" href="{{ route('video-projects.anchor', $id) }}">← Ảnh neo</a>
            </div>

            <div class="va-fields">
                <div class="va-field">
                    <label>Reference Type</label>
                    <div class="ctl sel">Environment (Môi trường)</div>
                </div>
                <div class="va-field">
                    <label>Asset Name</label>
                    <div class="ctl"><span>shipyard_hall_env_v1</span><span class="cnt">18/100</span></div>
                </div>
            </div>

            <div class="vp-tabs">
                <button class="vp-tab on" data-t="r-main">Reference Prompt / Instructions</button>
                <button class="vp-tab" data-t="r-neg">Negative Prompt</button>
            </div>

            <div class="va-body">
                <div class="va-lbl">REFERENCE INSTRUCTIONS <span>(Hướng dẫn sử dụng ảnh tham chiếu)</span></div>
                <textarea class="va-ta" data-v="r-main">Use this image as the shipbuilding hall environment reference.

Provide:
- Hall layout, columns, windows, lighting, floor markings.
- Overhead gantry cranes and rails.
- General atmosphere and scale.

Do NOT use any vessels from this image.
Focus only on the environment, camera position, and lighting.</textarea>
                <textarea class="va-ta" data-v="r-neg" hidden>vessel, yacht, hull, boat, open sky, sea, water, exterior daylight, text, watermark</textarea>
                <div class="va-count"><span data-c="r">0</span>/3000</div>
            </div>

            <div class="va-lbl" style="padding-left:14px">MODEL &amp; SETTINGS</div>
            <div class="va-set">
                <div class="va-field"><label>Model</label><div class="ctl sel">GPT Image 2</div></div>
                <div class="va-field"><label>Quality</label><div class="ctl sel">High</div></div>
                <div class="va-field"><label>Resolution</label><div class="ctl sel">1024 × 1536 (9:16)</div></div>
                <div class="va-field"><label>Stylization</label><div class="ctl sel">Low</div></div>
                <div class="va-field"><label>Seed</label><div class="ctl sel">Auto</div></div>
                <div class="va-field"><label>Variations</label><div class="ctl sel">2</div></div>
            </div>
            <div class="va-adv">Advanced ⌄</div>

            <div class="va-foot">
                <button class="vp-btn pri">Generate Reference Image →</button>
            </div>
        </div>

        <div class="vp-panel">
            <div class="va-head">
                <span class="n">4</span>
                <b>Render Reference Views</b>
                <em>(Render nhiều view tham chiếu)</em>
            </div>

            <div class="va-fields">
                <div class="va-field">
                    <label>Reference Type</label>
                    <div class="ctl sel">Subject Identity (Tàu chính)</div>
                </div>
                <div class="va-field">
                    <label>Asset</label>
                    <div class="ctl"><span>master_vessel_turnaround</span><span class="prog">In Progress</span></div>
                </div>
            </div>

            <div class="va-lbl" style="padding-left:14px">VIEWS TO GENERATE <span>(Các view cần render)</span></div>
            <div class="va-views">
                @foreach($views as [$name, $file])
                    <label class="va-view">
                        <input type="checkbox" checked>
                        <img src="{{ asset('renders/shots/'.$file) }}" alt="{{ $name }}">
                        <span>{{ $name }}</span>
                    </label>
                @endforeach
            </div>

            <div class="va-lbl" style="padding-left:14px">MODEL &amp; SETTINGS</div>
            <div class="va-set four">
                <div class="va-field"><label>Model</label><div class="ctl sel">GPT Image 2</div></div>
                <div class="va-field"><label>Quality</label><div class="ctl sel">High</div></div>
                <div class="va-field"><label>Resolution</label><div class="ctl sel">1024 × 1536 (9:16)</div></div>
                <div class="va-field"><label>Variations</label><div class="ctl sel">2</div></div>
            </div>

            <div class="va-foot">
                <button class="vp-btn pri">Generate Selected Views →</button>
            </div>
        </div>

    </div>
</div>
@endsection

@section('script')
<script>
(function () {
    var count = document.querySelector('[data-c="r"]');
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
