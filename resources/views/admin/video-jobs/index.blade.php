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
                        <th>Article</th>
                        <th width="70" class="text-center">Score</th>
                        <th width="140">Danh mục</th>
                        <th width="140">Người tạo</th>
                        <th width="90" class="text-center">Mood</th>
                        <th width="100" class="text-center">Parts</th>
                        <th width="200">Progress</th>
                        <th width="100" class="text-center">Cost (USD)</th>
                        <th width="90" class="text-center">Action</th>
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
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center text-muted py-4">Chưa có bài viết nào.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">
            {{ $articles->links('pagination::bootstrap-4') }}
        </div>
    </div>
</div>
@endsection
