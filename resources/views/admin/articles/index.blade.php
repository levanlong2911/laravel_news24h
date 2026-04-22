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
            {{-- Add Manual --}}
            <button class="btn btn-secondary btn-sm" data-toggle="modal" data-target="#modalAddManual">
                <i class="fas fa-pen"></i> Add Manual
            </button>

            {{-- Get Link --}}
            <button class="btn btn-success btn-sm" data-toggle="modal" data-target="#modalGetLink">
                <i class="fas fa-link"></i> Get Link
            </button>

            {{-- Gửi Claude / Tổng hợp --}}
            <button id="btnSendClaude" class="btn btn-info btn-sm" onclick="submitSendClaude()">
                <i class="fas fa-robot"></i>
                <span id="btnLabel">Send Claude</span>
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
                        <option value="{{ $kw->id }}" {{ (string)$keywordId === (string)$kw->id ? 'selected' : '' }}>
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
                    <tr><td colspan="11" class="text-center text-muted py-4">Chưa có bài viết nào.</td></tr>
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
{{-- ── ADD MANUAL MODAL ── --}}
<div class="modal fade" id="modalAddManual" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title"><i class="fas fa-pen mr-2"></i>Add Manual Article</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <form method="POST" action="{{ route('article.storeManual') }}">
                @csrf
                <div class="modal-body">
                    <div class="form-group mb-2">
                        <label class="small font-weight-bold">Source URL <span class="text-danger">*</span></label>
                        <input type="url" name="source_url" class="form-control form-control-sm"
                               placeholder="https://example.com/article..." required>
                    </div>
                    <div class="form-group mb-2">
                        <label class="small font-weight-bold">Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control form-control-sm"
                               placeholder="Tiêu đề bài viết..." required maxlength="500">
                    </div>
                    <div class="form-group mb-2">
                        <label class="small font-weight-bold">Keyword</label>
                        <select name="keyword_id" class="form-control form-control-sm">
                            <option value="">— Không chọn —</option>
                            @foreach($keywords as $kw)
                                <option value="{{ $kw->id }}">{{ $kw->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group mb-0">
                        <label class="small font-weight-bold">Content <span class="text-danger">*</span></label>
                        <textarea name="content" class="form-control form-control-sm" rows="10"
                                  placeholder="Nội dung bài viết..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="fas fa-save mr-1"></i>Lưu bài viết
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- ── GET LINK MODAL ── --}}
<div class="modal fade" id="modalGetLink" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title"><i class="fas fa-link mr-2"></i>Get Link — Crawl & Lưu Bài Viết</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">

                {{-- Form nhập --}}
                <div id="glForm">
                    <div class="form-group mb-2">
                        <label class="small font-weight-bold">URL bài viết</label>
                        <div class="input-group">
                            <input type="url" id="glUrl" class="form-control"
                                   placeholder="https://example.com/article...">
                            <div class="input-group-append">
                                <button class="btn btn-primary" onclick="glFetch()" id="glBtnFetch">
                                    <i class="fas fa-search"></i> Fetch & Lưu
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="form-group mb-0">
                        <label class="small font-weight-bold">Keyword <span class="text-muted">(tuỳ chọn)</span></label>
                        <select id="glKeyword" class="form-control form-control-sm" style="max-width:280px">
                            <option value="">— Không chọn —</option>
                            @foreach($keywords as $kw)
                                <option value="{{ $kw->id }}">{{ $kw->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                {{-- Loading --}}
                <div id="glLoading" class="d-none text-center py-4">
                    <i class="fas fa-spinner fa-spin fa-2x text-primary"></i>
                    <p id="glLoadingText" class="mt-2 text-muted small">Đang crawl nội dung...</p>
                </div>

                {{-- Error --}}
                <div id="glError" class="d-none alert alert-danger py-2 mt-2 mb-0 small"></div>

            </div>
        </div>
    </div>
</div>

{{-- Hidden save form --}}
<form id="glSaveForm" method="POST" action="{{ route('article.storeManual') }}" class="d-none">
    @csrf
    <input type="hidden" name="source_url"  id="glHiddenUrl">
    <input type="hidden" name="title"       id="glHiddenTitle">
    <input type="hidden" name="content"     id="glHiddenContent">
    <input type="hidden" name="keyword_id"  id="glHiddenKeyword">
</form>

<script>
function glFetch() {
    const url     = document.getElementById('glUrl').value.trim();
    const keyword = document.getElementById('glKeyword').value;
    const urlEl   = document.getElementById('glUrl');
    const kwEl    = document.getElementById('glKeyword');

    urlEl.classList.remove('is-invalid');
    kwEl.classList.remove('is-invalid');

    let valid = true;
    if (!url)     { urlEl.classList.add('is-invalid'); valid = false; }
    if (!keyword) { kwEl.classList.add('is-invalid');  valid = false; }
    if (!valid) return;

    document.getElementById('glForm').classList.add('d-none');
    document.getElementById('glError').classList.add('d-none');
    document.getElementById('glLoading').classList.remove('d-none');
    document.getElementById('glLoadingText').textContent = 'Đang crawl nội dung...';

    fetch('{{ url('/admin/getlink') }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}',
            'Accept': 'application/json',
        },
        body: JSON.stringify({ url })
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) {
            document.getElementById('glLoading').classList.add('d-none');
            document.getElementById('glForm').classList.remove('d-none');
            document.getElementById('glError').textContent = data.message || 'Không crawl được.';
            document.getElementById('glError').classList.remove('d-none');
            return;
        }
        // Crawl thành công → tự động lưu
        document.getElementById('glLoadingText').textContent = 'Đang lưu bài viết...';
        document.getElementById('glHiddenUrl').value     = url;
        document.getElementById('glHiddenTitle').value   = data.title || '';
        document.getElementById('glHiddenContent').value = data.content || '';
        document.getElementById('glHiddenKeyword').value = document.getElementById('glKeyword').value;
        document.getElementById('glSaveForm').submit();
    })
    .catch(() => {
        document.getElementById('glLoading').classList.add('d-none');
        document.getElementById('glForm').classList.remove('d-none');
        document.getElementById('glError').textContent = 'Lỗi kết nối server.';
        document.getElementById('glError').classList.remove('d-none');
    });
}

function glReset() {
    document.getElementById('glUrl').value = '';
    document.getElementById('glLoading').classList.add('d-none');
    document.getElementById('glError').classList.add('d-none');
    document.getElementById('glForm').classList.remove('d-none');
}

document.getElementById('glUrl').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') glFetch();
});

document.getElementById('modalGetLink').addEventListener('hidden.bs.modal', glReset);
</script>
@endsection
