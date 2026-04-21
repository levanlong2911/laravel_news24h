@extends('layouts.base', ['title' => 'Feed Items'])
@section('title', 'Feed Items')
@section('content')
<div class="container-fluid">

    {{-- ── HEADER ── --}}
    <div class="row align-items-center mb-3">
        <div class="col">
            <h4 class="mb-0">
                <i class="fas fa-list text-info"></i> Feed Items
            </h4>
            <small class="text-muted">Bài viết đã fetch từ RSS/Crawl. Chọn bài và push vào Articles để AI xử lý.</small>
        </div>
        <div class="col-auto">
            <a href="{{ route('feed-source.index') }}" class="btn btn-sm btn-outline-secondary">
                <i class="fas fa-arrow-left"></i> Feed Sources
            </a>
        </div>
    </div>

    {{-- ── ALERTS ── --}}
    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show py-2">
            {{ session('success') }}
            <button type="button" class="close" data-dismiss="alert">&times;</button>
        </div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show py-2">
            {{ session('error') }}
            <button type="button" class="close" data-dismiss="alert">&times;</button>
        </div>
    @endif

    {{-- ── FILTERS ── --}}
    <div class="card mb-3">
        <div class="card-body py-2">
            <form method="GET" action="{{ route('feed-source.items') }}" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="small mb-1">Category</label>
                    <select name="category_id" class="form-control form-control-sm">
                        <option value="">-- All --</option>
                        @foreach($categories as $cat)
                            <option value="{{ $cat->id }}" {{ $categoryId == $cat->id ? 'selected' : '' }}>
                                {{ $cat->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="small mb-1">Source</label>
                    <select name="feed_source_id" class="form-control form-control-sm">
                        <option value="">-- All --</option>
                        @foreach($sources as $src)
                            <option value="{{ $src->id }}" {{ $feedSourceId == $src->id ? 'selected' : '' }}>
                                {{ $src->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="small mb-1">Status</label>
                    <select name="status" class="form-control form-control-sm">
                        <option value="all" {{ $status === 'all' ? 'selected' : '' }}>All</option>
                        <option value="pending" {{ $status === 'pending' ? 'selected' : '' }}>Pending</option>
                        <option value="done" {{ $status === 'done' ? 'selected' : '' }}>Done</option>
                        <option value="failed" {{ $status === 'failed' ? 'selected' : '' }}>Failed</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-sm btn-secondary w-100">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- ── BULK ACTIONS ── --}}
    <div class="d-flex justify-content-between align-items-center mb-2">
        <div>
            <button type="button" class="btn btn-sm btn-warning" onclick="submitBulk('{{ route('feed-source.crawlContent') }}')">
                <i class="fas fa-download"></i> Crawl Content
            </button>
            <button type="button" class="btn btn-sm btn-primary ml-2" onclick="submitBulk('{{ route('feed-source.pushToArticles') }}')">
                <i class="fas fa-paper-plane"></i> Push to Articles
            </button>
            <button type="button" class="btn btn-sm btn-outline-danger ml-2"
                    onclick="document.getElementById('deleteAllForm').submit()">
                <i class="fas fa-trash"></i> Xóa Pending
            </button>
        </div>
        <small class="text-muted">{{ $items->total() }} items</small>
    </div>

    {{-- ── TABLE ── --}}
    <div class="card">
        <div class="card-body p-0">
            <table class="table table-sm table-hover mb-0">
                <thead class="thead-light">
                    <tr>
                        <th style="width:30px">
                            <input type="checkbox" id="selectAll">
                        </th>
                        <th>Tiêu đề</th>
                        <th style="width:12%">Source</th>
                        <th style="width:10%">Category</th>
                        <th style="width:8%">Status</th>
                        <th style="width:8%">Content</th>
                        <th style="width:10%">Ngày</th>
                        <th style="width:6%" class="text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($items as $item)
                    <tr class="{{ $item->status === 'done' ? 'table-light text-muted' : '' }}">
                        <td>
                            <input type="checkbox" value="{{ $item->id }}"
                                   class="item-check" {{ $item->status === 'done' ? 'disabled' : '' }}>
                        </td>
                        <td>
                            <a href="{{ $item->url }}" target="_blank" class="text-dark small">
                                {{ Str::limit($item->title, 80) }}
                            </a>
                            @if($item->article)
                                <span class="badge badge-success ml-1" title="Đã push">✓</span>
                            @endif
                        </td>
                        <td class="small text-muted">{{ $item->feedSource->name ?? '—' }}</td>
                        <td class="small">{{ $item->category->name ?? '—' }}</td>
                        <td>
                            @if($item->status === 'pending')
                                <span class="badge badge-warning">Pending</span>
                            @elseif($item->status === 'done')
                                <span class="badge badge-success">Done</span>
                            @elseif($item->status === 'failed')
                                <span class="badge badge-danger" title="{{ $item->error_message }}">Failed</span>
                            @else
                                <span class="badge badge-secondary">{{ $item->status }}</span>
                            @endif
                        </td>
                        <td class="small text-center">
                            @if($item->raw_content)
                                <span class="text-success" title="{{ strlen($item->raw_content) }} chars">
                                    <i class="fas fa-check"></i>
                                </span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="small text-muted">
                            {{ $item->published_at?->setTimezone('Asia/Bangkok')->format('d/m H:i')
                               ?? $item->created_at->setTimezone('Asia/Bangkok')->format('d/m H:i') }}
                        </td>
                        <td class="text-right">
                            <form method="POST" action="{{ route('feed-source.destroyItem', $item) }}"
                                  class="d-inline" onsubmit="return confirm('Xóa bài này?')">
                                @csrf @method('DELETE')
                                <button class="btn btn-xs btn-danger"><i class="fas fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">
                            <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
                            Chưa có feed items. Fetch source trước.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- ── PAGINATION ── --}}
    <div class="mt-3">
        {{ $items->appends(request()->query())->links() }}
    </div>

</div>

{{-- Hidden forms for bulk actions — completely outside the table --}}
<form id="bulkForm" method="POST" action="">
    @csrf
    <div id="bulkIds"></div>
</form>

<form id="deleteAllForm" method="POST"
      action="{{ route('feed-source.destroyItemsAll', array_filter(['category_id' => $categoryId, 'feed_source_id' => $feedSourceId])) }}"
      onsubmit="return confirm('Xóa tất cả pending?')">
    @csrf @method('DELETE')
</form>

<script>
document.getElementById('selectAll').addEventListener('change', function() {
    document.querySelectorAll('.item-check:not(:disabled)')
        .forEach(cb => cb.checked = this.checked);
});

function submitBulk(action) {
    const checked = document.querySelectorAll('.item-check:checked');
    if (checked.length === 0) {
        alert('Chưa chọn bài nào.');
        return;
    }
    const form    = document.getElementById('bulkForm');
    const container = document.getElementById('bulkIds');
    container.innerHTML = '';
    checked.forEach(cb => {
        const input = document.createElement('input');
        input.type  = 'hidden';
        input.name  = 'selected_ids[]';
        input.value = cb.value;
        container.appendChild(input);
    });
    form.action = action;
    form.submit();
}
</script>
@endsection
