@extends('layouts.base', ['title' => 'Video Jobs'])
@section('title', 'Video Jobs')
@section('content')
<div class="container-fluid">

    <div class="row align-items-center mb-3">
        <div class="col">
            <h4 class="mb-0">
                Video Jobs
                <span class="badge badge-secondary ml-2">{{ $articles->total() }}</span>
            </h4>
        </div>
        <div class="col-auto">
            <form id="bulk-delete-form" action="{{ route('video-job.bulk-destroy') }}" method="POST">
                @csrf
                @method('DELETE')
                <button type="button" id="btn-delete-all" class="btn btn-sm btn-danger" style="display:none" onclick="submitBulkDelete()">
                    <i class="fas fa-trash"></i> Xóa đã chọn (<span id="selected-count">0</span>)
                </button>
            </form>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show py-2">
            {{ session('success') }}
            <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
        </div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show py-2">
            {{ session('error') }}
            <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
        </div>
    @endif

    <div class="card card-default">
        <div class="card-body p-0">
            <table class="table table-sm table-bordered table-striped mb-0">
                <thead class="bg-dark text-white">
                    <tr>
                        <th width="36" class="text-center">
                            <input type="checkbox" id="check-all" title="Chọn tất cả">
                        </th>
                        <th>Article</th>
                        <th width="70" class="text-center">Score</th>
                        <th width="140">Danh mục</th>
                        <th width="140">Người tạo</th>
                        <th width="90" class="text-center">Mood</th>
                        <th width="100" class="text-center">Parts</th>
                        <th width="200">Progress</th>
                        <th width="100" class="text-center">Cost (USD)</th>
                        <th width="110" class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($articles as $article)
                    @php
                        $plan = $article->storyPlan;
                        $jobs = $plan?->videoJobs ?? collect();
                        $uploaded = $jobs->where('status', 'uploaded')->count();
                        $failed = $jobs->whereIn('status', ['quality_check_failed', 'upload_failed'])->count();
                        $totalCost = $jobs->sum('cost_total');
                    @endphp
                    <tr>
                        <td class="text-center align-middle">
                            <input type="checkbox" class="row-check" value="{{ $article->id }}">
                        </td>
                        <td class="align-middle">
                            <a href="{{ $plan ? route('video-job.show', $article) : route('article.show', $article) }}"
                               class="font-weight-bold text-dark d-block text-truncate" style="max-width:380px">
                                {{ Str::limit($article->title, 90) }}
                            </a>
                            @if($article->video_skipped_at)
                                <span class="badge badge-danger" title="{{ $article->video_skip_reason }}">đã skip -- bấm để retry</span>
                            @endif
                        </td>
                        <td class="text-center align-middle">
                            <span class="badge badge-secondary">{{ $article->viral_score ?? '—' }}</span>
                        </td>
                        <td class="align-middle small">{{ $article->category->name ?? '—' }}</td>
                        <td class="align-middle small">{{ $article->crawler->name ?? '—' }}</td>
                        <td class="text-center align-middle small">{{ $plan->mood ?? '—' }}</td>
                        <td class="text-center align-middle small">{{ $plan ? $jobs->count() . ' / ' . $plan->total_parts : '—' }}</td>
                        <td class="align-middle">
                            @if($plan)
                                <span class="badge badge-success">{{ $uploaded }} uploaded</span>
                                @if($failed > 0)
                                    <span class="badge badge-danger">{{ $failed }} failed</span>
                                @endif
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="text-center align-middle small">{{ $plan ? '$' . number_format($totalCost, 4) : '—' }}</td>
                        <td class="text-center align-middle">
                            @if($plan)
                                <a href="{{ route('video-job.show', $article) }}" class="btn btn-xs btn-outline-primary" title="View">
                                    <i class="fas fa-eye"></i>
                                </a>
                            @else
                                <form method="POST" action="{{ route('video-job.generate', $article) }}" class="d-inline"
                                      onsubmit="if (!confirm('Tạo Video AI cho bài này?')) return false; trackVideoGeneration('{{ $article->id }}', {{ \Illuminate\Support\Js::from(Str::limit($article->title, 60)) }}); return true;">
                                    @csrf
                                    <button class="btn btn-xs {{ $article->video_skipped_at ? 'btn-outline-danger' : 'btn-primary' }}" title="Tạo Video AI">
                                        <i class="fas fa-video"></i>
                                    </button>
                                </form>
                            @endif
                            <form method="POST" action="{{ route('video-job.destroy', $article) }}" class="d-inline"
                                  onsubmit="return confirm('Xóa bài \"{{ addslashes(Str::limit($article->title, 40)) }}\"?')">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-xs btn-danger" title="Xóa bài">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="text-center text-muted py-4">Chưa có bài viết nào.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">
            {{ $articles->links('pagination::bootstrap-4') }}
        </div>
    </div>
</div>

<script>
document.getElementById('check-all').addEventListener('change', function () {
    document.querySelectorAll('.row-check').forEach(cb => cb.checked = this.checked);
    updateBulkButton();
});

document.querySelectorAll('.row-check').forEach(cb => {
    cb.addEventListener('change', updateBulkButton);
});

function updateBulkButton() {
    const checked = document.querySelectorAll('.row-check:checked');
    const btn = document.getElementById('btn-delete-all');
    document.getElementById('selected-count').textContent = checked.length;
    btn.style.display = checked.length > 0 ? 'inline-block' : 'none';
}

function submitBulkDelete() {
    const checked = document.querySelectorAll('.row-check:checked');
    if (!checked.length || !confirm('Xóa video pipeline của ' + checked.length + ' bài đã chọn?')) return;
    const form = document.getElementById('bulk-delete-form');
    checked.forEach(cb => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'ids[]';
        input.value = cb.value;
        form.appendChild(input);
    });
    form.submit();
}
</script>
@endsection
