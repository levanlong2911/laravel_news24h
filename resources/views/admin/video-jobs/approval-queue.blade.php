@extends('layouts.base', ['title' => 'Approval Queue'])
@section('title', 'Video Approval Queue')
@section('content')
<div class="container-fluid">
    <div class="row align-items-center mb-3">
        <div class="col">
            <h4 class="mb-0">Video Approval Queue
                <span class="badge badge-warning ml-2">{{ $jobs->total() }}</span>
            </h4>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show py-2">
            {{ session('success') }}
            <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
        </div>
    @endif

    <div class="card card-default">
        <div class="card-body p-0">
            <table class="table table-sm table-bordered table-striped mb-0">
                <thead class="bg-dark text-white">
                    <tr>
                        <th>Article</th>
                        <th width="120">Danh mục</th>
                        <th width="70" class="text-center">Part</th>
                        <th width="90" class="text-center">Mood</th>
                        <th width="90" class="text-center">Cost</th>
                        <th width="90" class="text-center">Uploaded</th>
                        <th width="80" class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($jobs as $job)
                    <tr>
                        <td class="align-middle">
                            <a href="{{ route('video-approval.review', $job) }}" class="font-weight-bold text-dark d-block text-truncate" style="max-width:380px">
                                {{ Str::limit($job->storyPlan->article->title ?? '—', 80) }}
                            </a>
                        </td>
                        <td class="align-middle small">{{ $job->storyPlan->article->category->name ?? '—' }}</td>
                        <td class="text-center align-middle small">
                            {{ $job->part_number }} / {{ $job->storyPlan->total_parts }}
                        </td>
                        <td class="text-center align-middle small">{{ $job->storyPlan->mood }}</td>
                        <td class="text-center align-middle small">${{ number_format($job->cost_total, 4) }}</td>
                        <td class="text-center align-middle small text-muted">
                            {{ $job->updated_at->diffForHumans() }}
                        </td>
                        <td class="text-center align-middle">
                            <a href="{{ route('video-approval.review', $job) }}" class="btn btn-xs btn-primary">
                                <i class="fas fa-play-circle"></i> Review
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">
                        Không có video nào chờ duyệt.
                    </td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $jobs->links('pagination::bootstrap-4') }}</div>
    </div>
</div>
@endsection
