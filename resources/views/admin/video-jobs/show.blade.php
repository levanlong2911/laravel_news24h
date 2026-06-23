@extends('layouts.base', ['title' => 'Video Job — ' . Str::limit($article->title, 60)])
@section('title', 'Video Job')
@section('content')
<div class="container-fluid">

    <div class="row align-items-center mb-3">
        <div class="col">
            <h4 class="mb-0">{{ Str::limit($article->title, 90) }}</h4>
            <div class="text-muted small">
                Viral score: <span class="badge badge-secondary">{{ $article->viral_score }}</span>
                &nbsp;Mood: <span class="badge badge-light border">{{ $plan->mood }}</span>
                &nbsp;Parts: {{ $plan->videoJobs->count() }} / {{ $plan->total_parts }}
            </div>
        </div>
        <div class="col-auto">
            <a href="{{ route('video-job.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left"></i> Back
            </a>
            <a href="{{ route('article.show', $article) }}" class="btn btn-outline-primary btn-sm">
                <i class="fas fa-newspaper"></i> View Article
            </a>
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

    <div class="card card-default mb-3">
        <div class="card-body py-2">
            <strong>Hook:</strong> {{ $plan->hook }}<br>
            <strong>Narrative arc:</strong> <span class="text-muted">{{ $plan->narrative_arc }}</span>
        </div>
    </div>

    <div class="card card-default">
        <div class="card-body p-0">
            <table class="table table-sm table-bordered table-striped mb-0">
                <thead class="bg-dark text-white">
                    <tr>
                        <th width="60" class="text-center">Part</th>
                        <th width="120" class="text-center">Status</th>
                        <th width="100" class="text-center">Cost (USD)</th>
                        <th width="160" class="text-center">Claimed By</th>
                        <th width="140">Video / Thumbnail</th>
                        <th>Error</th>
                        <th width="100" class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($plan->videoJobs as $job)
                    @php
                        $statusBadge = [
                            'script_ready' => 'secondary',
                            'claimed' => 'info',
                            'rendering' => 'info',
                            'quality_check_passed' => 'primary',
                            'quality_check_failed' => 'danger',
                            'uploaded' => 'success',
                            'upload_failed' => 'danger',
                        ][$job->status] ?? 'secondary';
                    @endphp
                    <tr>
                        <td class="text-center align-middle">{{ $job->part_number }}</td>
                        <td class="text-center align-middle">
                            <span class="badge badge-{{ $statusBadge }}">{{ $job->status }}</span>
                        </td>
                        <td class="text-center align-middle small">${{ number_format($job->cost_total, 4) }}</td>
                        <td class="text-center align-middle small">{{ $job->claimed_by ?: '—' }}</td>
                        <td class="align-middle small">
                            @if($job->video_path)
                                <a href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($job->video_path) }}" target="_blank">video</a>
                            @endif
                            @if($job->thumbnail_path)
                                &nbsp;<a href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($job->thumbnail_path) }}" target="_blank">thumb</a>
                            @endif
                            @if($job->youtube_video_id)
                                <br><a href="https://youtube.com/watch?v={{ $job->youtube_video_id }}" target="_blank" class="text-danger">YouTube</a>
                            @endif
                        </td>
                        <td class="align-middle small text-danger text-truncate" style="max-width:240px" title="{{ $job->error_message }}">
                            {{ $job->error_message }}
                        </td>
                        <td class="text-center align-middle">
                            @if(in_array($job->status, ['quality_check_failed', 'upload_failed']))
                                <form method="POST" action="{{ route('video-job.rerender', $job) }}" onsubmit="return confirm('Reset part {{ $job->part_number }} để render lại?')">
                                    @csrf
                                    <button class="btn btn-xs btn-outline-warning" title="Re-render">
                                        <i class="fas fa-redo"></i>
                                    </button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
