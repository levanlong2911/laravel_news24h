@extends('layouts.base', ['title' => 'Auto Articles'])
@section('title', 'Auto Articles')
@section('content')
<div class="container-fluid">

    {{-- Header --}}
    <div class="row align-items-center mb-3">
        <div class="col">
            <h4 class="mb-0">
                Auto Articles
                <span class="badge badge-secondary ml-2">{{ $articles->total() }}</span>
            </h4>
        </div>
        <div class="col-auto d-flex align-items-center gap-2">
            {{-- Gửi Claude / Tổng hợp --}}
            <button id="btnSendClaude" class="btn btn-info btn-sm" onclick="submitSendClaude()">
                <i class="fas fa-robot"></i>
                <span id="btnLabel">Gửi Claude</span>
                <span id="selectedCount" class="badge badge-light ml-1">0</span>
            </button>

            {{-- Delete Selected (hiện khi có check) --}}
            <button id="btnDeleteSelected" class="btn btn-danger btn-sm d-none"
                    onclick="submitDeleteSelected()">
                <i class="fas fa-trash"></i> Delete Selected (<span id="deleteCount">0</span>)
            </button>

            {{-- Delete All --}}
            <form method="POST" action="{{ route('article.destroyAll') }}" class="d-inline"
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
            </form>
        </div>
    </div>

    {{-- Hidden forms --}}
    <form id="sendClaudeForm" method="POST" action="{{ route('article.sendToClaude') }}" class="d-none">
        @csrf
        <div id="claudeInputs"></div>
    </form>
    <form id="synthesizeForm" method="POST" action="{{ route('article.synthesize') }}" class="d-none">
        @csrf
        <div id="synthesizeInputs"></div>
    </form>
    <form id="bulkDeleteForm" method="POST" action="{{ route('article.destroySelected') }}" class="d-none">
        @csrf @method('DELETE')
        <input type="hidden" name="status"     value="{{ $status }}">
        <input type="hidden" name="keyword_id" value="{{ $keywordId }}">
        <div id="bulkDeleteInputs"></div>
    </form>

    {{-- Table --}}
    <div class="card card-default">
        <div class="card-body p-0">
            <table class="table table-sm table-bordered table-striped mb-0">
                <thead class="bg-dark text-white">
                    <tr>
                        <th width="36" class="text-center">
                            <input type="checkbox" id="checkAll" title="Chọn tất cả">
                        </th>
                        <th width="40" class="text-center">#</th>
                        <th width="60">Thumb</th>
                        <th>Title</th>
                        <th width="120">Source</th>
                        <th width="100">Crawled By</th>
                        <th width="110">Keyword</th>
                        <th width="70" class="text-center">Score</th>
                        <th width="80" class="text-center">Status</th>
                        <th width="105" class="text-center">Expires</th>
                        <th width="110" class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($articles as $i => $article)
                    <tr>
                        <td class="text-center align-middle">
                            <input type="checkbox" class="article-check" value="{{ $article->id }}">
                        </td>
                        <td class="text-center align-middle text-muted small">{{ $articles->firstItem() + $i }}</td>
                        <td class="text-center align-middle p-1">
                            @if($article->thumbnail)
                                <img src="{{ $article->thumbnail }}" width="50" height="35"
                                     style="object-fit:cover;border-radius:3px"
                                     onerror="this.style.display='none'">
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="align-middle">
                            <a href="{{ route('article.show', $article) }}" class="font-weight-bold text-dark d-block text-truncate"
                               style="max-width:350px" title="{{ $article->title }}">
                                {{ Str::limit($article->title, 85) }}
                            </a>
                            @if($article->faq && count($article->faq) > 0)
                                <span class="badge badge-light border small">FAQ {{ count($article->faq) }}</span>
                            @endif
                        </td>
                        <td class="align-middle small text-truncate" style="max-width:120px" title="{{ $article->source_name }}">
                            {{ $article->source_name ?: '—' }}
                        </td>
                        <td class="align-middle small text-truncate" style="max-width:100px">
                            @if($article->crawler)
                                <span title="{{ $article->crawler->email }}">
                                    {{ $article->crawler->name }}
                                </span>
                            @else
                                <span class="text-muted">Auto</span>
                            @endif
                        </td>
                        <td class="align-middle small">{{ $article->keyword->name ?? '—' }}</td>
                        <td class="text-center align-middle">
                            <span class="badge badge-{{ $article->viral_score >= 80 ? 'danger' : ($article->viral_score >= 50 ? 'warning' : 'secondary') }}">
                                {{ $article->viral_score }}
                            </span>
                        </td>
                        <td class="text-center align-middle">
                            @php $badge = ['published'=>'success','processing'=>'warning','pending'=>'info','failed'=>'danger'][$article->status] ?? 'secondary' @endphp
                            <span class="badge badge-{{ $badge }}">{{ $article->status }}</span>
                        </td>
                        <td class="text-center align-middle small" style="white-space:nowrap">
                            @if($article->expires_at)
                                <span title="{{ $article->expires_at->setTimezone('Asia/Bangkok')->format('d/m/Y H:i') }} ICT">
                                    {{ $article->expires_at->setTimezone('Asia/Bangkok')->format('d/m H:i') }}
                                </span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="text-center align-middle" style="white-space:nowrap">
                            <a href="{{ route('article.show', $article) }}" class="btn btn-xs btn-outline-primary" title="View">
                                <i class="fas fa-eye"></i>
                            </a>
                            <a href="{{ $article->source_url }}" target="_blank" class="btn btn-xs btn-outline-secondary" title="Source">
                                <i class="fas fa-external-link-alt"></i>
                            </a>
                            <form method="POST" action="{{ route('article.sendToClaude') }}" class="d-inline">
                                @csrf
                                <input type="hidden" name="selected_ids[]" value="{{ $article->id }}">
                                <button class="btn btn-xs btn-info" title="Gửi Claude"
                                        {{ $article->status === 'processing' ? 'disabled' : '' }}>
                                    <i class="fas fa-robot {{ $article->status === 'processing' ? 'fa-spin' : '' }}"></i>
                                </button>
                            </form>
                            <form method="POST" action="{{ route('article.destroy', $article) }}" class="d-inline"
                                  onsubmit="return confirm('Delete?')">
                                @csrf @method('DELETE')
                                <button class="btn btn-xs btn-outline-danger" title="Delete"><i class="fas fa-trash"></i></button>
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
            {{ $articles->appends(request()->query())->links() }}
        </div>
    </div>
</div>

<script>
// Checkbox logic
document.getElementById('checkAll').addEventListener('change', function () {
    document.querySelectorAll('.article-check').forEach(cb => cb.checked = this.checked);
    updateBtns();
});

document.addEventListener('change', function (e) {
    if (e.target.classList.contains('article-check')) updateBtns();
});

function updateBtns() {
    const checked = document.querySelectorAll('.article-check:checked');
    const count   = checked.length;

    document.getElementById('selectedCount').textContent = count;
    document.getElementById('deleteCount').textContent   = count;
    document.getElementById('btnDeleteSelected').classList.toggle('d-none', count === 0);

    // Đổi label button theo mode
    const label = document.getElementById('btnLabel');
    if (count > 1) {
        label.textContent = 'Tổng hợp ' + count + ' bài';
        document.getElementById('btnSendClaude').className = 'btn btn-warning btn-sm';
    } else {
        label.textContent = 'Gửi Claude';
        document.getElementById('btnSendClaude').className = 'btn btn-info btn-sm';
    }
}

function submitSendClaude() {
    const checked = document.querySelectorAll('.article-check:checked');
    const count   = checked.length;

    if (!count) {
        alert('Hãy chọn ít nhất 1 bài trước khi gửi Claude.');
        return;
    }

    const isSynthesize = count > 1;
    const msg = isSynthesize
        ? `Tổng hợp ${count} bài thành 1 bài duy nhất bằng Claude?\n\nLưu ý: tất cả bài phải cùng keyword/category.`
        : `Gửi 1 bài đã chọn sang Claude để viết lại?`;

    if (!confirm(msg)) return;

    const formId    = isSynthesize ? 'synthesizeForm'   : 'sendClaudeForm';
    const containerId = isSynthesize ? 'synthesizeInputs' : 'claudeInputs';
    const container = document.getElementById(containerId);

    container.innerHTML = '';
    checked.forEach(cb => {
        const input = document.createElement('input');
        input.type  = 'hidden';
        input.name  = 'selected_ids[]';
        input.value = cb.value;
        container.appendChild(input);
    });
    document.getElementById(formId).submit();
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
