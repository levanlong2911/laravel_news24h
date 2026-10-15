@if($cell['approved'])
    @php($chosen = collect($cell['approved']['artifacts'])
        ->firstWhere('id', $cell['approved']['selected_artifact_id']))
    <div class="vs-lbl">ĐÃ DUYỆT</div>
    @if($cell['approved']['needs_review'] !== null)
        <div class="m" style="color:var(--vp-amber-fg)">Cần kiểm tra lại: nguồn {{ $cell['approved']['needs_review'] }} đã đổi — render và duyệt lại.</div>
    @endif
    @if($cell['approved']['cost_recorded_has_ledger'])
        <div class="m">@include('video-projects.partials.cost-recorded', ['cell' => $cell['approved']])</div>
    @endif
    @if($chosen)
        <a class="frame" href="{{ $chosen['url'] }}" target="_blank">
            <img src="{{ $chosen['url'] }}" alt="">
        </a>
        {{-- <div class="m vp-mono">{{ $chosen['sha'] }}</div> --}}
    @endif
@endif

@if($cell['candidate'])
    @php($c = $cell['candidate'])
    <div class="vs-lbl">ỨNG VIÊN · {{ $c['status_label'] }}</div>

    @if($c['cost_recorded_has_ledger'])
        <div class="m">@include('video-projects.partials.cost-recorded', ['cell' => $c])</div>
    @endif

    @if($c['render_error'])
        <div class="m" style="color:var(--vp-amber-fg)">{{ $c['render_error'] }}</div>
    @endif

    @foreach($c['artifacts'] as $artifact)
        <form method="POST" class="vs-cand js-kf-action" data-scene="{{ $s['scene_id'] }}" data-busy="Đang duyệt…"
              action="{{ route('video-projects.scene-keyframe-approve', [$id, $c['id']]) }}">
            @csrf
            <input type="hidden" name="artifact_id" value="{{ $artifact['id'] }}">
            <a class="frame" href="{{ $artifact['url'] }}" target="_blank">
                <img src="{{ $artifact['url'] }}" alt="">
            </a>
            <span class="m vp-mono">{{ $artifact['sha'] }}</span>
            <button class="vp-btn ok sm">✓ Duyệt</button>
        </form>
    @endforeach

    @if($c['resumable'])
        <form method="POST" class="js-kf-action" data-scene="{{ $s['scene_id'] }}" data-busy="Đang render…"
              action="{{ route('video-projects.scene-keyframe-retry', [$id, $c['id']]) }}">
            @csrf
            <input type="hidden" name="prompt_sha256" value="{{ $c['prompt_sha256'] }}">
            <button class="vp-btn sm">
                {{ $c['status'] === 'failed' ? '↻ Thử lại' : '▸ Tiếp tục render' }}
            </button>
        </form>
    @endif
@elseif(! $cell['approved'])
    <div class="frame">chưa render</div>
@endif
