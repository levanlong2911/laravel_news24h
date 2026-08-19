@php
    $rp = $shot?->render_plan ?? [];
    $notes = $scene['director_notes'] ?? [];
    $warnings = collect($quality['warnings'] ?? []);
@endphp

<div class="vp-panel">
    <div class="vp-sh">
        <h2>{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::headline($scene['id'])) }}</h2>
        @if($shot)
            <span class="vp-tag {{ $shot->status === 'rendered' ? 'done' : 'cur' }}">
                {{ \App\Enums\VideoShotStatus::tryFrom($shot->status)?->label() ?? $shot->status }}
            </span>
        @endif
        <span class="grow"></span>
        <span class="meta vp-mono">Scene ID: {{ $scene['id'] }}</span>
    </div>

    <div class="vp-sec">
        <h3>A. THÔNG TIN SCENE</h3>
        <div class="vp-two">
            <dl class="vp-f"><dt>Beat</dt><dd>{{ $shot?->beat ?? '—' }}</dd></dl>
            <dl class="vp-f"><dt>Transition Mode</dt><dd><span class="vp-none">chưa có trong plan</span></dd></dl>

            <dl class="vp-f"><dt>Shot</dt><dd class="vp-mono">{{ $shot?->shot_code ?? '—' }}</dd></dl>
            <dl class="vp-f"><dt>Continuity Group</dt><dd><span class="vp-none">chưa có trong plan</span></dd></dl>

            <dl class="vp-f"><dt>Type</dt><dd>{{ $scene['purpose'] ?? '—' }}</dd></dl>
            <dl class="vp-f"><dt>Trạng thái</dt><dd>
                @if($shot)
                    <span class="vp-tag {{ $shot->status === 'rendered' ? 'done' : 'cur' }}">{{ \App\Enums\VideoShotStatus::tryFrom($shot->status)?->label() ?? $shot->status }}</span>
                @else — @endif
            </dd></dl>

            <dl class="vp-f"><dt>Kind</dt><dd>{{ $shot?->kind ?? '—' }}</dd></dl>
            <dl class="vp-f"><dt>Yêu cầu trạng thái</dt><dd>
                @if($requires)<span class="vp-state {{ $proof ? '' : 'miss' }}">{{ $requires }}</span>
                @else <span class="vp-none">không ràng buộc</span> @endif
            </dd></dl>

            <dl class="vp-f"><dt>Duration (dự kiến)</dt><dd>{{ isset($rp['duration']) ? $rp['duration'].'s' : '—' }}</dd></dl>
            <dl class="vp-f"><dt>Sinh ra trạng thái</dt><dd>
                @php($p = $shot?->render_plan['proves_state'] ?? null)
                @if($p)<span class="vp-state out">{{ $p }}</span>
                @else <span class="vp-none">scene không sinh trạng thái</span> @endif
            </dd></dl>
        </div>
    </div>

    <div class="vp-sec">
        <h3>B. STATE TRANSITION <span style="font-weight:400;color:#64748b">(Ngữ cảnh thi công)</span></h3>
        <div class="vp-flow">
            <div class="vp-box">
                <span class="cap">Input state</span><span class="sub">Yêu cầu trước</span>
                @if($requires)
                    <span class="vp-state {{ $proof ? '' : 'miss' }}">{{ $requires }}</span>
                    <p>{{ $proof ? 'Mắt chuỗi '.$proof->render_plan['chain_key'].' chứng minh trạng thái này.' : 'Chưa mắt chuỗi nào chứng minh — clip chưa có ảnh nguồn.' }}</p>
                @else
                    <span class="vp-none">không ràng buộc</span>
                @endif
            </div>
            <div class="vp-gap">→</div>
            <div class="vp-box mid">
                <span class="cap">Change / Action</span><span class="sub">Hành động trong scene này</span>
                <p style="margin-top:0;color:#0f172a;font-size:.78rem">{{ $scene['objective'] ?? '—' }}</p>
            </div>
            <div class="vp-gap">→</div>
            <div class="vp-box">
                <span class="cap">Output state</span><span class="sub">Tạo ra sau scene này</span>
                @if($p ?? null)<span class="vp-state out">{{ $p }}</span>
                @else <span class="vp-none">chưa có</span>
                <p>Chỉ shot <code>kind=chain</code> mới khai <code>proves_state</code>.</p>
                @endif
            </div>
        </div>
    </div>

    <div class="vp-sec">
        <h3>C. MỤC TIÊU &amp; YÊU CẦU</h3>
        <div class="vp-goals">
            <div>
                <ul>
                    @if($scene['objective'] ?? null)<li>{{ $scene['objective'] }}</li>@endif
                    @if($notes['composition_note'] ?? null)<li>{{ $notes['composition_note'] }}</li>@endif
                    @foreach($notes['micro_physics'] ?? [] as $m)<li>{{ $m }}</li>@endforeach
                    @if(($scene['objective'] ?? null) === null && ($notes['composition_note'] ?? null) === null)
                        <li class="vp-none">Scene chưa khai objective hay composition_note.</li>
                    @endif
                </ul>
            </div>
            <div>
                @forelse($warnings as $w)
                    <div class="vp-warn" style="margin-bottom:8px">
                        <b>⚠ CHẤT LƯỢNG RENDERPLAN — {{ $w['code'] }}</b>
                        {{ $w['message'] }}
                        @if($w['code'] === 'NO_ENVIRONMENT')
                            <br>→ Đi tới
                            <a href="{{ route('video-session.scene', [$session->id, $scene['id'], 'references']) }}">References</a>
                            để thêm ảnh môi trường
                        @endif
                    </div>
                @empty
                    <div class="vp-info">Không có cảnh báo RenderPlan nào.</div>
                @endforelse
            </div>
        </div>
    </div>

    <div class="vp-foot">
        <a class="vp-btn" href="{{ route('video-session.show', $session->id) }}">Về tổng quan</a>
        @if($step->next())
            <a class="vp-btn pri" href="{{ route('video-session.scene', [$session->id, $scene['id'], $step->next()->value]) }}">Tiếp tục →</a>
        @endif
    </div>
</div>
