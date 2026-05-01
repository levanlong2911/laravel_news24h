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
            <small class="text-muted">Top 10 bài viral nhất theo từng keyword • Tự xóa sau 24h</small>
        </div>
        <div class="col-auto d-flex align-items-center gap-2">
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
    @endphp
    <div class="row mb-3">
        @foreach(['pending'=>['warning','clock'], 'done'=>['success','check']] as $s=>[$color,$icon])
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
                    <option value="all"     {{ $status==='all'     ?'selected':'' }}>All Status</option>
                    <option value="pending" {{ $status==='pending' ?'selected':'' }}>Pending</option>
                    <option value="done"    {{ $status==='done'    ?'selected':'' }}>Done</option>
                </select>
                <button class="btn btn-primary btn-sm mr-2">Filter</button>
                <a href="{{ route('raw-article.index') }}" class="btn btn-outline-secondary btn-sm mr-4">Reset</a>

                {{-- Quick fetch --}}
                <div class="d-flex align-items-center ml-auto">
                    <select id="kwSelect" class="form-control form-control-sm mr-1" style="width:180px">
                        <option value="">-- keyword --</option>
                        @foreach($keywords as $kw)
                            <option value="{{ $kw->id }}">{{ $kw->name }}</option>
                        @endforeach
                    </select>
                    <button type="button" class="btn btn-outline-danger btn-sm" onclick="fetchOne()">
                        <i class="fas fa-search"></i> Fetch
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- ── GROUPED BY KEYWORD ── --}}
    @forelse($grouped as $group)
        @php
            $kw          = $group['keyword'];
            $top         = $group['top'];
            $recent      = $group['recent'];
            $stats       = $group['stats'];
            $recommended = $group['recommended'] ?? null;
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
                    @if($stats['done'] > 0)
                        <span class="badge badge-success">{{ $stats['done'] }} done</span>
                    @endif
                </div>
                <form method="POST" action="{{ route('raw-article.fetchOne') }}" class="d-inline">
                    @csrf
                    <input type="hidden" name="keyword_id" value="{{ $kw->id }}">
                    <button class="btn btn-sm btn-outline-light">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                </form>
                <form method="POST" action="{{ route('raw-article.clearRefetch') }}" class="d-inline ml-1"
                    onsubmit="return confirm('Xóa tất cả {{ $stats['total'] }} bài của {{ $kw->name }} và fetch lại?')">
                    @csrf
                    <input type="hidden" name="keyword_id" value="{{ $kw->id }}">
                    <button class="btn btn-sm btn-outline-danger" title="Xóa bài cũ & fetch lại">
                        <i class="fas fa-trash-restore"></i> Clear & Refetch
                    </button>
                </form>
            </div>

            <div class="card-body p-0">

                {{-- ── Table: Top 10 Viral ── --}}
                @if($top->isNotEmpty())
                <div class="px-3 pt-2 pb-1 d-flex align-items-center"
                     style="background:#fff3cd;border-bottom:1px solid #ffc107">
                    <i class="fas fa-trophy text-warning mr-2"></i>
                    <strong class="small text-uppercase" style="letter-spacing:.05em">
                        Top {{ $top->count() }} Viral
                    </strong>
                    <span class="ml-2 small text-muted">— ưu tiên viral Facebook (fb score)</span>
                </div>

                {{-- ── Recommendation Banner ── --}}
                @if($recommended)
                <div style="background:linear-gradient(135deg,#fff8e1,#fffde7);border-left:4px solid #ffc107;padding:12px 16px;border-bottom:1px solid #ffe082">
                    <div class="d-flex align-items-start">
                        <div class="mr-2" style="font-size:1.3rem;line-height:1">⭐</div>
                        <div class="flex-grow-1">
                            <div class="font-weight-bold text-warning mb-1" style="font-size:.7rem;letter-spacing:.08em;text-transform:uppercase">
                                Đề xuất viết lại — viral nhất trên Facebook
                            </div>
                            <a href="{{ $recommended['article']->url }}" target="_blank"
                               class="font-weight-bold text-dark d-block mb-2" style="font-size:.92rem;line-height:1.4">
                                {{ $recommended['article']->title }}
                            </a>
                            <div class="d-flex flex-wrap" style="gap:5px">
                                @foreach($recommended['reasons'] as $r)
                                <span class="badge badge-light border text-dark" style="font-size:.75em;padding:3px 8px;font-weight:normal">
                                    {{ $r['icon'] }} {{ $r['text'] }}
                                </span>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
                @endif

                @include('admin.raw-articles._table', [
                    'items'         => $top,
                    'kw'            => $kw,
                    'recommendedId' => $recommended['article']->id ?? null,
                ])
                @endif

                {{-- ── Table: 20 Newest ── --}}
                @if($recent->isNotEmpty())
                <div class="px-3 pt-2 pb-1 d-flex align-items-center"
                     style="background:#d1ecf1;border-bottom:1px solid #bee5eb;border-top:{{ $top->isNotEmpty() ? '2px solid #dee2e6' : 'none' }}">
                    <i class="fas fa-clock text-info mr-2"></i>
                    <strong class="small text-uppercase" style="letter-spacing:.05em">
                        {{ $recent->count() }} Mới Nhất
                    </strong>
                    <span class="ml-2 small text-muted">— sắp xếp theo thời gian đăng</span>
                </div>
                @include('admin.raw-articles._table', ['items' => $recent, 'kw' => $kw])
                @endif

            </div>
            <div class="card-footer py-1 text-right small text-muted">
                Expires in ~{{ optional(($top->merge($recent))->first()?->expires_at)->diffForHumans() ?? '—' }}
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

<style>
@keyframes fadeIn { from { opacity:0; transform:translateY(8px); } to { opacity:1; transform:translateY(0); } }
</style>
<div id="toastWrap" style="position:fixed;bottom:24px;right:24px;z-index:9999;min-width:260px"></div>

<script>
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

            const tr = btn.closest('tr');

            // Update row style
            tr.classList.add('table-light');

            // Update status badge
            const statusTd = tr.querySelector('td:nth-last-child(2)');
            if (statusTd) statusTd.innerHTML = '<span class="badge badge-success">Done</span>';

            // Update view button (first button in actions td)
            const actionsTd = tr.querySelector('td:last-child');
            if (actionsTd && data.article_url) {
                const viewBtn = actionsTd.querySelector('a, span.btn-outline-secondary');
                if (viewBtn) viewBtn.outerHTML = `<a href="${data.article_url}" class="btn btn-xs btn-outline-primary" title="View Article"><i class="fas fa-eye"></i></a>`;
            }

            // Replace download button
            btn.outerHTML = '<span class="badge badge-success">Saved</span>';
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
</script>
@endsection
