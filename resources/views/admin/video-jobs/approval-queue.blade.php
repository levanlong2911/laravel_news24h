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
        <div class="col-auto">
            <form id="bulk-delete-form" action="{{ route('video-approval.bulk-destroy') }}" method="POST">
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
                        <th width="120">Danh mục</th>
                        <th width="70" class="text-center">Part</th>
                        <th width="90" class="text-center">Mood</th>
                        <th width="90" class="text-center">Cost</th>
                        <th width="90" class="text-center">Uploaded</th>
                        <th width="120" class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($jobs as $job)
                    <tr>
                        <td class="text-center align-middle">
                            <input type="checkbox" class="row-check" value="{{ $job->id }}">
                        </td>
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
                            <form action="{{ route('video-approval.destroy', $job) }}" method="POST" class="d-inline" onsubmit="return confirm('Xóa video này?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-xs btn-danger">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted py-4">
                        Không có video nào chờ duyệt.
                    </td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $jobs->links('pagination::bootstrap-4') }}</div>
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
    if (!checked.length || !confirm('Xóa ' + checked.length + ' video đã chọn?')) return;
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
