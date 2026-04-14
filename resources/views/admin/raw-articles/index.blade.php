@extends('layouts.base', ['title' => 'Trending News Feed'])
@section('title', 'Trending News Feed')
@section('content')
<div class="container-fluid">

    {{-- ── HEADER ── --}}
    <div class="row align-items-center mb-3">
        <div class="col">
            <h4 class="mb-0">
                <i class="fas fa-fire text-danger"></i> Trending News Feed
            </h4>
            <small class="text-muted">Top 10 bài viral nhất theo từng keyword • Tự xóa sau 24h • Chọn nhiều bài → Generate</small>
        </div>
        <div class="col-auto d-flex align-items-center gap-2">
            {{-- Generate Selected (hiện khi có check) --}}
            <button id="btnGenerateSelected" class="btn btn-success btn-sm d-none"
                    onclick="submitSelected()">
                <i class="fas fa-robot"></i> Generate Selected (<span id="selectedCount">0</span>)
            </button>
            <form method="POST" action="{{ route('raw-article.fetchAll') }}" class="d-inline"
                  onsubmit="return confirm('Xóa tất cả data cũ và fetch lại từ Google News?')">
                @csrf
                <button class="btn btn-danger btn-sm">
                    <i class="fas fa-sync-alt"></i> Fetch All Keywords
                </button>
            </form>
            <a href="{{ route('article.index') }}" class="btn btn-outline-success btn-sm ml-1">
                <i class="fas fa-newspaper"></i> Articles
            </a>
        </div>
    </div>

    {{-- Hidden form for bulk generate --}}
    <form id="bulkGenerateForm" method="POST" action="{{ route('raw-article.generateSelected') }}" class="d-none">
        @csrf
        <div id="bulkInputs"></div>
    </form>

    {{-- ── ALERTS ── --}}
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

    {{-- ── GLOBAL STATS ── --}}
    @php
        $allStats = \App\Models\RawArticle::selectRaw('status, count(*) as cnt')->groupBy('status')->pluck('cnt','status');
        $isGenerating = ($allStats['generating'] ?? 0) > 0;
    @endphp
    <div class="row mb-3">
        @foreach(['pending'=>['warning','clock'], 'generating'=>['info','spinner fa-spin'], 'done'=>['success','check'], 'failed'=>['danger','times']] as $s=>[$color,$icon])
        <div class="col-sm-3">
            <div class="info-box mb-2" style="min-height:60px">
                <span class="info-box-icon bg-{{ $color }}" style="line-height:60px;font-size:20px">
                    <i class="fas fa-{{ $icon }}"></i>
                </span>
                <div class="info-box-content" style="padding:8px 10px">
                    <span class="info-box-text text-capitalize">{{ $s }}</span>
                    <span class="info-box-number">{{ $allStats[$s] ?? 0 }}</span>
                </div>
            </div>
        </div>
        @endforeach
    </div>

    {{-- ── FILTERS ── --}}
    <div class="card card-default mb-3">
        <div class="card-body py-2">
            <form method="GET" class="form-inline flex-wrap">
                <select name="keyword_id" class="form-control form-control-sm mr-2">
                    <option value="">All Keywords</option>
                    @foreach($keywords as $kw)
                        <option value="{{ $kw->id }}" {{ $keywordId === $kw->id ? 'selected' : '' }}>
                            {{ $kw->name }}
                        </option>
                    @endforeach
                </select>
                <select name="status" class="form-control form-control-sm mr-2">
                    <option value="all"        {{ $status==='all'        ?'selected':'' }}>All Status</option>
                    <option value="pending"    {{ $status==='pending'    ?'selected':'' }}>Pending</option>
                    <option value="generating" {{ $status==='generating' ?'selected':'' }}>Generating</option>
                    <option value="done"       {{ $status==='done'       ?'selected':'' }}>Done</option>
                    <option value="failed"     {{ $status==='failed'     ?'selected':'' }}>Failed</option>
                </select>
                <button class="btn btn-primary btn-sm mr-2">Filter</button>
                <a href="{{ route('raw-article.index') }}" class="btn btn-outline-secondary btn-sm mr-4">Reset</a>

                {{-- Quick actions --}}
                <div class="d-flex align-items-center ml-auto">
                    <select id="kwSelect" class="form-control form-control-sm mr-1" style="width:180px">
                        <option value="">-- keyword --</option>
                        @foreach($keywords as $kw)
                            <option value="{{ $kw->id }}">{{ $kw->name }}</option>
                        @endforeach
                    </select>
                    <button type="button" class="btn btn-outline-danger btn-sm mr-1" onclick="fetchOne()">
                        <i class="fas fa-search"></i> Fetch
                    </button>
                    {{-- <button type="button" class="btn btn-outline-primary btn-sm" onclick="generateKeyword()">
                        <i class="fas fa-robot"></i> Generate Pending
                    </button> --}}
                </div>
            </form>
        </div>
    </div>

    {{-- ── GROUPED BY KEYWORD ── --}}
    @forelse($grouped as $group)
        @php
            $kw    = $group['keyword'];
            $items = $group['articles'];
            $stats = $group['stats'];
        @endphp

        <div class="card card-default mb-4" id="kw-{{ $kw->id }}">
            {{-- Keyword Header --}}
            <div class="card-header d-flex align-items-center py-2"
                style="background:linear-gradient(135deg,#1a252f,#2c3e50);color:#fff">
                <div class="flex-grow-1">
                    <strong style="font-size:1rem">
                        <i class="fas fa-tag mr-1 text-warning"></i>
                        {{ $kw->name }}
                    </strong>
                    <span class="ml-2 small text-white-50">{{ $kw->search_keyword }}</span>
                </div>
                <div class="d-flex align-items-center gap-2 mr-3">
                    @if($stats['pending'] > 0)
                        <span class="badge badge-warning">{{ $stats['pending'] }} pending</span>
                    @endif
                    @if($stats['generating'] > 0)
                        <span class="badge badge-info">
                            <i class="fas fa-spinner fa-spin"></i> {{ $stats['generating'] }} generating
                        </span>
                    @endif
                    @if($stats['done'] > 0)
                        <span class="badge badge-success">{{ $stats['done'] }} done</span>
                    @endif
                    @if($stats['failed'] > 0)
                        <span class="badge badge-danger">{{ $stats['failed'] }} failed</span>
                    @endif
                </div>
                {{-- Generate all pending cho keyword này --}}
                @if($stats['pending'] > 0)
                <form method="POST" action="{{ route('raw-article.generateKeyword') }}" class="d-inline mr-2">
                    @csrf
                    <input type="hidden" name="keyword_id" value="{{ $kw->id }}">
                    <button class="btn btn-sm btn-warning"
                        onclick="return confirm('Generate all {{ $stats['pending'] }} pending articles for {{ $kw->name }}?')">
                        <i class="fas fa-robot"></i> Generate All ({{ $stats['pending'] }})
                    </button>
                </form>
                @endif
                {{-- Fetch fresh news cho keyword này --}}
                <form method="POST" action="{{ route('raw-article.fetchOne') }}" class="d-inline">
                    @csrf
                    <input type="hidden" name="keyword_id" value="{{ $kw->id }}">
                    <button class="btn btn-sm btn-outline-light">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                </form>
                {{-- Clear & Refetch: xóa bài cũ → fetch lại để có timestamp chính xác --}}
                <form method="POST" action="{{ route('raw-article.clearRefetch') }}" class="d-inline ml-1"
                    onsubmit="return confirm('Xóa tất cả {{ $stats['total'] }} bài của {{ $kw->name }} và fetch lại?')">
                    @csrf
                    <input type="hidden" name="keyword_id" value="{{ $kw->id }}">
                    <button class="btn btn-sm btn-outline-danger" title="Xóa bài cũ & fetch lại">
                        <i class="fas fa-trash-restore"></i> Clear & Refetch
                    </button>
                </form>
            </div>

            {{-- Articles Table --}}
            <div class="card-body p-0">
                <table class="table table-sm table-bordered table-hover mb-0">
                    <thead style="background:#f4f6f9">
                        <tr class="text-center small text-uppercase text-muted" style="font-size:.75rem">
                            <th width="30">
                                <input type="checkbox" class="kw-check-all"
                                       data-kw="{{ $kw->id }}" title="Select all in keyword">
                            </th>
                            <th width="30">#</th>
                            <th width="55">Thumb</th>
                            <th class="text-left">Title</th>
                            <th width="65">Score</th>
                            <th width="50">Top</th>
                            <th width="55">Stories</th>
                            <th width="90">Posted</th>
                            <th width="75">Status</th>
                            <th width="155">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($items as $i => $raw)
                        <tr class="{{ $raw->status==='generating' ? 'table-warning' : ($raw->status==='done' ? 'table-light' : '') }}">

                            {{-- Checkbox --}}
                            <td class="text-center align-middle">
                                @if(in_array($raw->status, ['pending','failed']))
                                <input type="checkbox" class="article-checkbox"
                                       data-id="{{ $raw->id }}" data-kw="{{ $kw->id }}">
                                @endif
                            </td>

                            {{-- # --}}
                            <td class="text-center align-middle text-muted small font-weight-bold">
                                {{ $i + 1 }}
                            </td>

                            {{-- Thumb --}}
                            <td class="text-center align-middle p-1">
                                @if($raw->thumbnail)
                                    <img src="{{ $raw->thumbnail }}"
                                        width="52" height="36"
                                        style="object-fit:cover;border-radius:4px;display:block;margin:auto"
                                        onerror="this.style.display='none'">
                                @elseif($raw->source_icon)
                                    <img src="{{ $raw->source_icon }}"
                                        width="20" height="20"
                                        style="border-radius:3px;display:block;margin:auto"
                                        onerror="this.style.display='none'">
                                @else
                                    <div style="width:52px;height:36px;background:#eee;border-radius:4px;margin:auto;display:flex;align-items:center;justify-content:center">
                                        <i class="fas fa-image text-muted" style="font-size:12px"></i>
                                    </div>
                                @endif
                            </td>

                            {{-- Title --}}
                            <td class="align-middle" style="max-width:0">
                                <a href="{{ $raw->url }}" target="_blank"
                                    class="d-block font-weight-bold text-dark text-truncate"
                                    title="{{ $raw->title }}"
                                    style="max-width:100%">
                                    {{ $raw->title }}
                                </a>
                                <div class="small text-muted text-truncate" style="max-width:100%">
                                    <span class="text-primary font-weight-bold">{{ $raw->source }}</span>
                                    @if($raw->snippet)
                                        &nbsp;·&nbsp;{{ Str::limit($raw->snippet, 90) }}
                                    @endif
                                </div>
                            </td>

                            {{-- Viral Score --}}
                            <td class="text-center align-middle">
                                @php
                                    $sc = $raw->viral_score;
                                    $badgeColor = $sc >= 100 ? 'danger' : ($sc >= 70 ? 'warning' : ($sc >= 40 ? 'info' : 'secondary'));
                                @endphp
                                <span class="badge badge-pill badge-{{ $badgeColor }}"
                                    style="font-size:.85em;padding:5px 8px">
                                    {{ $sc }}
                                </span>
                            </td>

                            {{-- Top Story --}}
                            <td class="text-center align-middle">
                                @if($raw->top_story)
                                    <span title="Google News Top Story" style="font-size:1.1rem">🔥</span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>

                            {{-- Stories count --}}
                            <td class="text-center align-middle">
                                <span class="font-weight-bold {{ $raw->stories_count >= 5 ? 'text-danger' : ($raw->stories_count >= 2 ? 'text-warning' : 'text-muted') }}">
                                    {{ $raw->stories_count }}
                                </span>
                            </td>

                            {{-- Published date --}}
                            <td class="text-center align-middle small text-muted" style="white-space:nowrap"
                                title="{{ $raw->published_date }}">
                                {{ $raw->posted_ago }}
                            </td>

                            {{-- Status --}}
                            <td class="text-center align-middle">
                                @if($raw->status === 'pending')
                                    <span class="badge badge-warning">Pending</span>
                                @elseif($raw->status === 'generating')
                                    <span class="badge badge-info">
                                        <i class="fas fa-spinner fa-spin"></i> AI
                                    </span>
                                @elseif($raw->status === 'done')
                                    <span class="badge badge-success">Done</span>
                                @elseif($raw->status === 'failed')
                                    <span class="badge badge-danger">Failed</span>
                                @endif
                            </td>

                            {{-- Actions --}}
                            <td class="text-center align-middle" style="white-space:nowrap">
                                {{-- Source link --}}
                                <a href="{{ $raw->url }}" target="_blank"
                                    class="btn btn-xs btn-outline-secondary" title="Source">
                                    <i class="fas fa-external-link-alt"></i>
                                </a>

                                {{-- Main action --}}
                                @if($raw->status === 'generating')
                                    <button class="btn btn-xs btn-warning" disabled>
                                        <i class="fas fa-spinner fa-spin"></i>
                                    </button>
                                @else
                                    <form method="POST" action="{{ route('raw-article.save', $raw) }}" class="d-inline">
                                        @csrf
                                        <button class="btn btn-xs {{ $raw->status === 'done' ? 'btn-outline-primary' : 'btn-primary' }}"
                                                title="{{ $raw->status === 'done' ? 'Tải lại (bài mới)' : 'Tải & lưu bài viết' }}">
                                            <i class="fas fa-download"></i>
                                        </button>
                                    </form>
                                @endif

                                {{-- Delete --}}
                                <form method="POST" action="{{ route('raw-article.destroy', $raw) }}"
                                    class="d-inline" onsubmit="return confirm('Delete?')">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-xs btn-outline-danger" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            {{-- Footer --}}
            <div class="card-footer py-1 text-right small text-muted">
                Expires in ~{{ optional($items->first()?->expires_at)->diffForHumans() ?? '—' }}
            </div>
        </div>
    @empty
        <div class="card">
            <div class="card-body text-center py-5 text-muted">
                <i class="fas fa-inbox fa-3x mb-3 d-block text-secondary"></i>
                <h5>No trending news yet</h5>
                <p>Click <strong>Fetch All Keywords</strong> to pull the latest from Google News.</p>
                <form method="POST" action="{{ route('raw-article.fetchAll') }}">
                    @csrf
                    <button class="btn btn-danger"><i class="fas fa-sync-alt"></i> Fetch Now</button>
                </form>
            </div>
        </div>
    @endforelse

</div>

{{-- Toast notification --}}
<style>
@keyframes fadeIn { from { opacity:0; transform:translateY(8px); } to { opacity:1; transform:translateY(0); } }
</style>
<div id="toastWrap" style="position:fixed;bottom:24px;right:24px;z-index:9999;min-width:260px"></div>

<script>
// ── Toast ────────────────────────────────────────────────────────────────────
function showToast(message, type = 'success') {
    const id  = 'toast-' + Date.now();
    const bg  = type === 'success' ? '#28a745' : '#dc3545';
    const icon = type === 'success' ? 'fa-check-circle' : 'fa-times-circle';
    const html = `
        <div id="${id}" style="background:${bg};color:#fff;padding:12px 18px;border-radius:8px;
             margin-top:8px;box-shadow:0 4px 12px rgba(0,0,0,.25);display:flex;align-items:center;gap:10px;
             animation:fadeIn .2s ease">
            <i class="fas ${icon}"></i>
            <span>${message}</span>
        </div>`;
    document.getElementById('toastWrap').insertAdjacentHTML('beforeend', html);
    setTimeout(() => document.getElementById(id)?.remove(), 3500);
}

// ── Download / Save article ──────────────────────────────────────────────────
document.addEventListener('click', function(e) {
    const btn = e.target.closest('.btn-save-article');
    if (!btn) return;

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

    fetch(btn.dataset.url, {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': '{{ csrf_token() }}',
            'Accept': 'application/json',
        }
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showToast(data.message, 'success');
            // Đổi button → View nếu không phải already
            if (!data.already) {
                btn.outerHTML = '<span class="badge badge-success">Saved</span>';
            } else {
                btn.outerHTML = '<span class="badge badge-secondary">Existed</span>';
            }
        } else {
            showToast(data.message || 'Lỗi không xác định.', 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-download"></i>';
        }
    })
    .catch(() => {
        showToast('Lỗi kết nối server.', 'error');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-download"></i>';
    });
});

// ── Multi-select logic ───────────────────────────────────────────────────────
function updateSelectedCount() {
    const checked = document.querySelectorAll('.article-checkbox:checked');
    const count = checked.length;
    document.getElementById('selectedCount').textContent = count;
    const btn = document.getElementById('btnGenerateSelected');
    if (count > 0) {
        btn.classList.remove('d-none');
    } else {
        btn.classList.add('d-none');
    }
}

function submitSelected() {
    const checked = document.querySelectorAll('.article-checkbox:checked');
    if (checked.length === 0) { alert('Chưa chọn bài nào.'); return; }
    if (!confirm(`Generate ${checked.length} bài đã chọn bằng Claude AI?`)) return;

    const container = document.getElementById('bulkInputs');
    container.innerHTML = '';
    checked.forEach(cb => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'selected_ids[]';
        input.value = cb.dataset.id;
        container.appendChild(input);
    });
    document.getElementById('bulkGenerateForm').submit();
}

// Delegate: checkbox changes
document.addEventListener('change', function(e) {
    if (e.target.classList.contains('article-checkbox')) {
        updateSelectedCount();
    }
    // Select all in keyword
    if (e.target.classList.contains('kw-check-all')) {
        const kw = e.target.dataset.kw;
        document.querySelectorAll(`.article-checkbox[data-kw="${kw}"]`)
            .forEach(cb => cb.checked = e.target.checked);
        updateSelectedCount();
    }
});

function fetchOne() {
    const kwId = document.getElementById('kwSelect').value;
    if (!kwId) { alert('Please select a keyword'); return; }
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '{{ route('raw-article.fetchOne') }}';
    form.innerHTML = `<input type="hidden" name="_token" value="{{ csrf_token() }}">
                      <input type="hidden" name="keyword_id" value="${kwId}">`;
    document.body.appendChild(form);
    form.submit();
}

function generateKeyword() {
    const kwId = document.getElementById('kwSelect').value;
    if (!kwId) { alert('Please select a keyword'); return; }
    if (!confirm('Generate all pending articles for this keyword?')) return;
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '{{ route('raw-article.generateKeyword') }}';
    form.innerHTML = `<input type="hidden" name="_token" value="{{ csrf_token() }}">
                      <input type="hidden" name="keyword_id" value="${kwId}">`;
    document.body.appendChild(form);
    form.submit();
}

@if($isGenerating)
// Auto-refresh sau 10s nếu có bài đang generating
setTimeout(() => location.reload(), 10000);
@endif
</script>
@endsection
