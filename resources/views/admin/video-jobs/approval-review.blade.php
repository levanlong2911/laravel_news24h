@extends('layouts.base', ['title' => 'Review Video'])
@section('title', 'Review Video — Part ' . $job->part_number)
@section('content')
<div class="container-fluid">
    <div class="row align-items-center mb-3">
        <div class="col">
            <h4 class="mb-0">Review Video — Part {{ $job->part_number }} / {{ $plan->total_parts }}</h4>
            <small class="text-muted">{{ $plan->article->title ?? '' }}</small>
        </div>
        <div class="col-auto">
            <a href="{{ route('video-approval.index') }}" class="btn btn-sm btn-outline-secondary">← Back to queue</a>
        </div>
    </div>

    @if(session('error'))
        <div class="alert alert-danger py-2">{{ session('error') }}</div>
    @endif

    <div class="row">
        {{-- Video preview --}}
        <div class="col-md-5">
            <div class="card card-default mb-3">
                <div class="card-header"><b>Video Preview</b></div>
                <div class="card-body p-2">
                    @if($videoUrl)
                        <video controls class="w-100 rounded" style="max-height:480px">
                            <source src="{{ $videoUrl }}" type="video/mp4">
                        </video>
                    @else
                        <p class="text-muted text-center py-4">Video not available</p>
                    @endif
                </div>
            </div>
            @if($thumbUrl)
            <div class="card card-default mb-3">
                <div class="card-header"><b>Thumbnail</b></div>
                <div class="card-body p-2 text-center">
                    <img src="{{ $thumbUrl }}" class="img-fluid rounded" style="max-height:200px">
                </div>
            </div>
            @endif
        </div>

        {{-- Metadata --}}
        <div class="col-md-7">
            <div class="card card-default mb-3">
                <div class="card-header"><b>Nội dung cần kiểm tra</b></div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tr><th width="120">Hook</th><td>{{ $script['hook'] ?? $plan->hook }}</td></tr>
                        <tr><th>Narrative arc</th><td>{{ $plan->narrative_arc }}</td></tr>
                        <tr><th>Mood</th><td><span class="badge badge-info">{{ $plan->mood }}</span></td></tr>
                        <tr><th>Content type</th><td>{{ $plan->content_type }}</td></tr>
                        <tr><th>CTA</th><td>{{ $script['cta'] ?? '—' }}</td></tr>
                        <tr>
                            <th>Key facts</th>
                            <td>
                                @forelse($keyFacts as $f)
                                    <span class="badge badge-secondary mr-1">{{ $f }}</span>
                                @empty <span class="text-muted">—</span>
                                @endforelse
                            </td>
                        </tr>
                        <tr><th>Cost</th><td>${{ number_format($job->cost_total, 4) }}</td></tr>
                    </table>
                </div>
            </div>

            {{-- Scenes --}}
            <div class="card card-default mb-3">
                <div class="card-header"><b>Scenes ({{ count($script['scenes'] ?? []) }})</b></div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <thead class="bg-dark text-white"><tr><th width="40">#</th><th width="70">Beat</th><th>Narration</th></tr></thead>
                        <tbody>
                        @foreach($script['scenes'] ?? [] as $scene)
                            <tr>
                                <td class="align-middle small text-muted">{{ $scene['scene_id'] }}</td>
                                <td class="align-middle"><span class="badge badge-secondary">{{ $scene['beat'] }}</span></td>
                                <td class="small">{{ Str::limit($scene['narration'], 120) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Action buttons --}}
            <div class="card card-default">
                <div class="card-body">
                    <div class="d-flex" style="gap:8px">
                        <form method="POST" action="{{ route('video-approval.approve', $job) }}" class="d-inline"
                              onsubmit="return confirm('Approve và publish bài này?')">
                            @csrf
                            <button class="btn btn-success"><i class="fas fa-check mr-1"></i> Approve & Publish</button>
                        </form>

                        <form method="POST" action="{{ route('video-approval.regenerate', $job) }}" class="d-inline"
                              onsubmit="return confirm('Render lại video này? Python sẽ tự nhận job.')">
                            @csrf
                            <button class="btn btn-warning"><i class="fas fa-redo mr-1"></i> Regenerate</button>
                        </form>

                        <form method="POST" action="{{ route('video-approval.reject', $job) }}" class="d-inline" id="rejectForm">
                            @csrf
                            <input type="hidden" name="note" id="rejectNote">
                            <button type="button" class="btn btn-danger" onclick="doReject()">
                                <i class="fas fa-times mr-1"></i> Reject
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function doReject() {
    const note = prompt('Lý do reject (tuỳ chọn):');
    if (note === null) return;   // cancelled
    document.getElementById('rejectNote').value = note;
    document.getElementById('rejectForm').submit();
}
</script>
@endsection
