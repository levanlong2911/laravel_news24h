<div class="vp-panel">
    <div class="vp-sh">
        <h2>SCENE 06: SIDE SHELL ASSEMBLY</h2>
        <span class="vp-tag cur">In Progress</span>
        <span class="grow"></span>
        <span class="meta vp-mono">Scene ID: SC06</span>
    </div>

    <div class="vp-tabs">
        <button class="vp-tab on" data-pt="main">Prompt chính</button>
        <button class="vp-tab" data-pt="neg">Negative Prompt</button>
    </div>

    <div class="vp-pgrid">
        <div>
            <h3 style="margin:0 0 9px;font-size:.68rem;letter-spacing:.06em;color:#64748b">PROMPT CHÍNH</h3>
            <textarea class="vp-ta" data-pv="main">Inside a covered shipbuilding hall, overhead gantry cranes running on rails beneath the roof.

A large steel side shell plating panel is being lifted by slings from an overhead crane and positioned against the vertical frames of the hull. Workers on scaffolding align and clamp the panel in place.

Industrial lighting, realistic documentary style, consistent with previous scene's environment, camera angle unchanged, no exterior, no sea, no sky.</textarea>
            <textarea class="vp-ta" data-pv="neg" hidden>business suit, blazer, office clothing, no hard hat, no safety vest, low angle, worms eye view, looking up, empty shipyard, clean empty floor, finished yacht, glossy paint, glazed windows, open sea, sky</textarea>
            <div class="vp-pmeta">
                <span>Số ký tự: <b data-cnt>0</b></span>
                <span class="grow"></span>
                <button class="vp-btn sm">Tối ưu prompt</button>
            </div>
        </div>

        <div class="vp-gp">
            <h4>Thông số generate</h4>
            <div class="vp-row"><label>Model</label><div class="ctl">GPT Image 2</div></div>
            <div class="vp-row"><label>Quality</label><div class="ctl">High</div></div>
            <div class="vp-row"><label>Resolution</label><div class="ctl">1024 × 1536 (9:16)</div></div>
            <div class="vp-row"><label>Stylization</label><div class="ctl">Low</div></div>
            <div class="vp-row"><label>Seed</label><div class="ctl">Auto</div></div>
            <div class="vp-row"><label>Variations</label><div class="ctl">2</div></div>
            <button class="vp-btn sm" style="width:100%;margin-top:6px">Advanced ⌄</button>
        </div>
    </div>

    <div class="vp-foot">
        <a class="vp-btn" href="{{ route('video-session.scene', [$session->id, $scene['id'], 'planning']) }}">← Quay lại</a>
        <button class="vp-btn">Lưu prompt</button>
        @if($step->next())
            <a class="vp-btn pri" href="{{ route('video-session.scene', [$session->id, $scene['id'], $step->next()->value]) }}">Lưu &amp; Tiếp tục →</a>
        @endif
    </div>
</div>

<script>
(function () {
    var count = document.querySelector('[data-cnt]');
    function sync() {
        var vis = document.querySelector('[data-pv]:not([hidden])');
        count.textContent = vis.value.length;
    }
    document.querySelectorAll('[data-pt]').forEach(function (t) {
        t.addEventListener('click', function () {
            document.querySelectorAll('[data-pt]').forEach(function (x) { x.classList.toggle('on', x === t); });
            document.querySelectorAll('[data-pv]').forEach(function (v) { v.hidden = v.dataset.pv !== t.dataset.pt; });
            sync();
        });
    });
    document.querySelectorAll('[data-pv]').forEach(function (v) { v.addEventListener('input', sync); });
    sync();
})();
</script>
