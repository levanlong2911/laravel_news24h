@extends('layouts.base', ['title' => 'Auto Articles'])
@section('title', 'Auto Articles')
@section('content')
<div class="container-fluid">

    {{-- Header --}}
    <div class="row mb-3">
        <div class="col-md-8">
            <h4 class="mb-0">Auto Articles
                <span class="badge badge-secondary ml-2">{{ $articles->total() }}</span>
            </h4>
        </div>
        <div class="col-md-4 text-right">
            <form method="POST" action="{{ route('article.clearCache') }}" class="d-inline">
                @csrf
                <button class="btn btn-outline-secondary btn-sm">Clear Cache</button>
            </form>
            {{-- Delete Selected (hiện khi có check) --}}
            <button id="btnDeleteSelected" class="btn btn-danger btn-sm ml-1 d-none"
                    onclick="submitDeleteSelected()">
                <i class="fas fa-trash"></i> Delete Selected (<span id="selectedCount">0</span>)
            </button>
            <form method="POST" action="{{ route('article.destroyAll') }}" class="d-inline ml-1"
                  onsubmit="return confirm('Xóa tất cả {{ $articles->total() }} bài đang hiển thị? Không thể hoàn tác!')">
                @csrf @method('DELETE')
                <input type="hidden" name="status"     value="{{ $status }}">
                <input type="hidden" name="keyword_id" value="{{ $keywordId }}">
                <button class="btn btn-outline-danger btn-sm">
                    <i class="fas fa-trash-alt"></i> Delete All ({{ $articles->total() }})
                </button>
            </form>
        </div>
    </div>

    {{-- Alerts --}}
    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show">
            {{ session('success') }}
            <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
        </div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show">
            {{ session('error') }}
            <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
        </div>
    @endif

    {{-- Filters --}}
    <div class="card card-default mb-3">
        <div class="card-body py-2">
            <form method="GET" class="form-inline flex-wrap gap-2">
                <select name="status" class="form-control form-control-sm mr-2">
                    <option value="all"       {{ $status === 'all'       ? 'selected' : '' }}>All Status</option>
                    <option value="published" {{ $status === 'published' ? 'selected' : '' }}>Published</option>
                    <option value="processing"{{ $status === 'processing'? 'selected' : '' }}>Processing</option>
                    <option value="pending"   {{ $status === 'pending'   ? 'selected' : '' }}>Pending</option>
                    <option value="failed"    {{ $status === 'failed'    ? 'selected' : '' }}>Failed</option>
                </select>
                <select name="keyword_id" class="form-control form-control-sm mr-2">
                    <option value="">All Keywords</option>
                    @foreach($keywords as $kw)
                        <option value="{{ $kw->id }}" {{ $keywordId === $kw->id ? 'selected' : '' }}>
                            {{ $kw->name }}
                        </option>
                    @endforeach
                </select>
                <button class="btn btn-primary btn-sm mr-2">Filter</button>
                <a href="{{ route('article.index') }}" class="btn btn-outline-secondary btn-sm">Reset</a>

                {{-- Generate 1 keyword --}}
                <div class="ml-auto d-flex align-items-center">
                    <select name="keyword_id_gen" id="kwGen" class="form-control form-control-sm mr-1" style="width:180px">
                        <option value="">-- pick keyword --</option>
                        @foreach($keywords as $kw)
                            <option value="{{ $kw->id }}">{{ $kw->name }}</option>
                        @endforeach
                    </select>
                    <button type="button" class="btn btn-info btn-sm" onclick="generateOne()">
                        <i class="fas fa-play"></i> Generate One
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- Stats bar --}}
    @php
        $stats = \App\Models\Article::selectRaw('status, count(*) as cnt')->groupBy('status')->pluck('cnt','status');
    @endphp
    <div class="row mb-3">
        <div class="col-sm-3">
            <div class="info-box bg-success">
                <span class="info-box-icon"><i class="fas fa-check"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Published</span>
                    <span class="info-box-number">{{ $stats['published'] ?? 0 }}</span>
                </div>
            </div>
        </div>
        <div class="col-sm-3">
            <div class="info-box bg-warning">
                <span class="info-box-icon"><i class="fas fa-spinner"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Processing</span>
                    <span class="info-box-number">{{ $stats['processing'] ?? 0 }}</span>
                </div>
            </div>
        </div>
        <div class="col-sm-3">
            <div class="info-box bg-info">
                <span class="info-box-icon"><i class="fas fa-clock"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Pending</span>
                    <span class="info-box-number">{{ $stats['pending'] ?? 0 }}</span>
                </div>
            </div>
        </div>
        <div class="col-sm-3">
            <div class="info-box bg-danger">
                <span class="info-box-icon"><i class="fas fa-times"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Failed</span>
                    <span class="info-box-number">{{ $stats['failed'] ?? 0 }}</span>
                </div>
            </div>
        </div>
    </div>

    {{-- Table --}}
    <div class="card card-default">
        <div class="card-body p-0">
            <table class="table table-sm table-bordered table-striped mb-0">
                <thead class="bg-dark text-white">
                    <tr>
                        <th width="36" class="text-center">
                            <input type="checkbox" id="checkAll" title="Chọn tất cả">
                        </th>
                        <th width="50">#</th>
                        <th width="60">Thumb</th>
                        <th>Title</th>
                        <th width="110">Keyword</th>
                        <th width="70" class="text-center">Score</th>
                        <th width="80" class="text-center">Status</th>
                        <th width="90" class="text-center">Expires</th>
                        <th width="110" class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($articles as $i => $article)
                    <tr>
                        <td class="text-center">
                            <input type="checkbox" class="article-check" value="{{ $article->id }}">
                        </td>
                        <td class="text-center text-muted small">{{ $articles->firstItem() + $i }}</td>
                        <td class="text-center">
                            @if($article->thumbnail)
                                <img src="{{ $article->thumbnail }}" width="50" height="35" style="object-fit:cover;border-radius:3px" onerror="this.style.display='none'">
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>
                            <a href="{{ route('article.show', $article) }}" class="font-weight-bold text-dark">
                                {{ Str::limit($article->title, 80) }}
                            </a>
                            <div class="small text-muted">
                                {{ $article->source_name }}
                                @if($article->faq && count($article->faq) > 0)
                                    &nbsp;<span class="badge badge-light border">FAQ {{ count($article->faq) }}</span>
                                @endif
                            </div>
                        </td>
                        <td class="small">{{ $article->keyword->name ?? '—' }}</td>
                        <td class="text-center">
                            <span class="badge badge-{{ $article->viral_score >= 80 ? 'danger' : ($article->viral_score >= 50 ? 'warning' : 'secondary') }}">
                                {{ $article->viral_score }}
                            </span>
                        </td>
                        <td class="text-center">
                            @php $badge = ['published'=>'success','processing'=>'warning','pending'=>'info','failed'=>'danger'][$article->status] ?? 'secondary' @endphp
                            <span class="badge badge-{{ $badge }}">{{ $article->status }}</span>
                        </td>
                        <td class="text-center small text-muted">
                            @if($article->expires_at)
                                {{ $article->expires_at->diffForHumans() }}
                            @else —
                            @endif
                        </td>
                        <td class="text-center">
                            <a href="{{ route('article.show', $article) }}" class="btn btn-xs btn-outline-primary" title="View">
                                <i class="fas fa-eye"></i>
                            </a>
                            <a href="{{ $article->source_url }}" target="_blank" class="btn btn-xs btn-outline-secondary" title="Source">
                                <i class="fas fa-external-link-alt"></i>
                            </a>
                            @if($article->status === 'published')
                                <form method="POST" action="{{ route('article.unpublish', $article) }}" class="d-inline">
                                    @csrf
                                    <button class="btn btn-xs btn-outline-warning" title="Unpublish"><i class="fas fa-eye-slash"></i></button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('article.publish', $article) }}" class="d-inline">
                                    @csrf
                                    <button class="btn btn-xs btn-outline-success" title="Publish"><i class="fas fa-check"></i></button>
                                </form>
                            @endif
                            <form method="POST" action="{{ route('article.destroy', $article) }}" class="d-inline" onsubmit="return confirm('Delete?')">
                                @csrf @method('DELETE')
                                <button class="btn btn-xs btn-outline-danger" title="Delete"><i class="fas fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted py-4">No articles yet. Click "Generate All Keywords" to start.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">
            {{ $articles->appends(request()->query())->links() }}
        </div>
    </div>
</div>

{{-- Hidden form for bulk delete --}}
<form id="bulkDeleteForm" method="POST" action="{{ route('article.destroySelected') }}" class="d-none">
    @csrf @method('DELETE')
    <input type="hidden" name="status"     value="{{ $status }}">
    <input type="hidden" name="keyword_id" value="{{ $keywordId }}">
    <div id="bulkDeleteInputs"></div>
</form>

<script>
function generateOne() {
    const kwId = document.getElementById('kwGen').value;
    if (!kwId) { alert('Please select a keyword'); return; }
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '{{ route('article.generateOne') }}';
    form.innerHTML = '<input type="hidden" name="_token" value="{{ csrf_token() }}">'
                   + '<input type="hidden" name="keyword_id" value="' + kwId + '">';
    document.body.appendChild(form);
    form.submit();
}

// Checkbox logic
document.getElementById('checkAll').addEventListener('change', function () {
    document.querySelectorAll('.article-check').forEach(cb => cb.checked = this.checked);
    updateDeleteBtn();
});

document.addEventListener('change', function (e) {
    if (e.target.classList.contains('article-check')) updateDeleteBtn();
});

function updateDeleteBtn() {
    const checked = document.querySelectorAll('.article-check:checked');
    const btn     = document.getElementById('btnDeleteSelected');
    document.getElementById('selectedCount').textContent = checked.length;
    btn.classList.toggle('d-none', checked.length === 0);
}

function submitDeleteSelected() {
    const checked = document.querySelectorAll('.article-check:checked');
    if (!checked.length) return;
    if (!confirm('Xóa ' + checked.length + ' bài đã chọn? Không thể hoàn tác!')) return;

    const container = document.getElementById('bulkDeleteInputs');
    container.innerHTML = '';
    checked.forEach(cb => {
        const input = document.createElement('input');
        input.type  = 'hidden';
        input.name  = 'selected_ids[]';
        input.value = cb.value;
        container.appendChild(input);
    });
    document.getElementById('bulkDeleteForm').submit();
}
</script>
@endsection
