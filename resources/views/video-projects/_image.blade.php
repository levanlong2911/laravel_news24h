@php
    $qa = [
        'Identity consistency' => 'PASS',
        'Environment match' => 'PASS',
        'State correctness' => 'PASS',
        'Geometry alignment' => 'PASS',
        'Lighting & mood' => 'PASS',
        'Forbidden components' => 'PASS',
    ];
    $refs = [
        'Environment' => '1 ảnh',
        'Subject Identity' => '3 ảnh',
        'Previous State' => '1 ảnh',
        'Construction Master' => '1 ảnh',
    ];
@endphp

<div class="vp-panel">
    <div class="vp-sh">
        <h2>SCENE 06: SIDE SHELL ASSEMBLY</h2>
        <span class="vp-tag cur">In Progress</span>
        <span class="grow"></span>
        <span class="meta vp-mono">Scene ID: SC06</span>
    </div>

    <div class="vp-tabs">
        <button class="vp-tab on">Kết quả (2/2)</button>
        <button class="vp-tab">Lịch sử generate</button>
        <button class="vp-tab">QA &amp; Chi tiết</button>
        <span class="grow"></span>
        <button class="vp-btn sm" style="align-self:center;margin-right:16px">↻ Regenerate</button>
    </div>

    <div class="vp-cands" style="grid-template-columns:1fr 1fr 300px">
        <div class="vp-cd" style="border-color:#16a34a">
            <div class="hd">
                <b>CANDIDATE 1</b>
                <span style="margin-left:auto;font-size:.62rem;font-weight:700;padding:2px 8px;border-radius:4px;background:#dcfce7;color:#166534">★ Đề xuất</span>
            </div>
            <div class="vp-empty" style="margin:0;border:0;border-radius:0;aspect-ratio:3/4;display:grid;place-content:center">chưa nối dữ liệu</div>
            <div class="ft">
                <span>GPT Image 2</span><span>1024×1536</span><span>High</span>
                <span>Seed: Auto</span><span>Created: 2026-08-15 10:32:11</span>
                <span>Cost <b>$0.17</b></span>
            </div>
        </div>

        <div class="vp-cd">
            <div class="hd"><b>CANDIDATE 2</b></div>
            <div class="vp-empty" style="margin:0;border:0;border-radius:0;aspect-ratio:3/4;display:grid;place-content:center">chưa nối dữ liệu</div>
            <div class="ft">
                <span>GPT Image 2</span><span>1024×1536</span><span>High</span>
                <span>Seed: Auto</span><span>Created: 2026-08-15 10:32:31</span>
                <span>Cost <b>$0.17</b></span>
            </div>
        </div>

        <div class="vp-right">
            <div class="vp-qa">
                <h4>QA tự động (Candidate 1)</h4>
                @foreach($qa as $name => $verdict)
                    <div class="vp-qline">
                        <span>{{ $name }}</span>
                        <span class="grow"></span>
                        <span style="color:#166534;font-weight:700;font-size:.68rem;letter-spacing:.05em">{{ $verdict }}</span>
                    </div>
                @endforeach
                <div style="display:flex;align-items:center;gap:10px;margin-top:11px;padding-top:11px;border-top:1px solid #e2e8f0;font-size:.75rem">
                    <span>Tổng điểm</span>
                    <span style="flex:1;height:5px;border-radius:3px;background:#e2e8f0;overflow:hidden">
                        <span style="display:block;height:100%;width:96%;background:#16a34a"></span>
                    </span>
                    <b style="font-variant-numeric:tabular-nums">96/100</b>
                </div>
            </div>

            <div class="vp-qa">
                <h4>Reference summary</h4>
                @foreach($refs as $name => $count)
                    <div class="vp-qline">
                        <span>{{ $name }}</span>
                        <span class="grow"></span>
                        <b>{{ $count }}</b>
                    </div>
                @endforeach
                <div style="display:flex;align-items:center;margin-top:11px;padding-top:11px;border-top:1px solid #e2e8f0;font-size:.75rem">
                    <span>Tổng cộng</span>
                    <span class="grow" style="flex:1"></span>
                    <b>6 ảnh</b>
                </div>
            </div>
        </div>
    </div>

    <div class="vp-foot" style="justify-content:space-between">
        <div style="display:flex;gap:9px;flex-wrap:wrap">
            <button class="vp-btn ok">✓ Duyệt làm keyframe K6 (Canonical)</button>
            <button class="vp-btn">↻ Tạo lại (Regenerate)</button>
            <a class="vp-btn" href="{{ route('video-session.scene', [$session->id, $scene['id'], 'prompt']) }}">✎ Chỉnh sửa prompt</a>
            <button class="vp-btn dg">✕ Loại bỏ (Reject)</button>
        </div>
        <button class="vp-btn pri">Hoàn tất → (Unlock next scene)</button>
    </div>
</div>
