@extends('layouts.base', ['title' => 'RSS News Feed'])
@section('title', 'RSS News Feed')
@section('content')
<div class="container-fluid">

    {{-- ── HEADER ── --}}
    <div class="row align-items-center mb-3">
        <div class="col">
            <h4 class="mb-0">
                <i class="fas fa-rss text-warning"></i> RSS News Feed
            </h4>
            <small class="text-muted">Tin tức từ các nguồn RSS • Chỉ lấy bài trong 15h gần nhất • Tự xóa sau 24h</small>
        </div>
        <div class="col-auto d-flex align-items-center gap-2">
            <form method="POST" action="{{ route('news-rss.autoDetect') }}" class="d-inline"
                  onsubmit="return confirm('Auto-detect RSS URL cho tất cả nguồn chưa có? Có thể mất vài phút.')">
                @csrf
                <button class="btn btn-info btn-sm">
                    <i class="fas fa-magic"></i> Auto Detect RSS
                </button>
            </form>
            <form method="POST" action="{{ route('news-rss.fetchAll') }}" class="d-inline"
                  onsubmit="return confirm('Fetch lại tất cả nguồn RSS đang active?')">
                @csrf
                <button class="btn btn-warning btn-sm">
                    <i class="fas fa-sync-alt"></i> Fetch All Sources
                </button>
            </form>
            <a href="{{ route('news-web.index') }}" class="btn btn-outline-secondary btn-sm ml-1">
                <i class="fas fa-globe"></i> Quản lý News Web
            </a>
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
            {{-- Filter form — standalone, không lồng form khác bên trong --}}
            <form method="GET" class="form-inline flex-wrap align-items-center">
                <select name="category_id" class="form-control form-control-sm mr-2">
                    <option value="">All Categories</option>
                    @foreach($categories as $cat)
                        <option value="{{ $cat->id }}" {{ $categoryId === $cat->id ? 'selected' : '' }}>
                            {{ $cat->name }}
                        </option>
                    @endforeach
                </select>
                <select name="news_web_id" class="form-control form-control-sm mr-2">
                    <option value="">All Sources</option>
                    @foreach($allNewsWebs as $web)
                        <option value="{{ $web->id }}" {{ $newsWebId === $web->id ? 'selected' : '' }}>
                            {{ $web->domain }}
                        </option>
                    @endforeach
                </select>
                <select name="status" class="form-control form-control-sm mr-2">
                    <option value="all"     {{ $status === 'all'     ? 'selected' : '' }}>All Status</option>
                    <option value="pending" {{ $status === 'pending' ? 'selected' : '' }}>Pending</option>
                    <option value="done"    {{ $status === 'done'    ? 'selected' : '' }}>Done</option>
                </select>
                <button class="btn btn-primary btn-sm mr-2">Filter</button>
                <a href="{{ route('news-rss.index') }}" class="btn btn-outline-secondary btn-sm">Reset</a>
            </form>

            {{-- Category actions — tách riêng khỏi filter form để tránh conflict tên field --}}
            <div class="d-flex align-items-center mt-2 gap-1" id="categoryActionWrap">
                <select id="catActionSelect" class="form-control form-control-sm mr-1" style="width:200px">
                    <option value="">-- chọn category --</option>
                    @foreach($categories as $cat)
                        <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                    @endforeach
                </select>

                <form method="POST" action="{{ route('news-rss.fetchByCategory') }}" id="formFetch" class="d-inline">
                    @csrf
                    <input type="hidden" name="category_id" id="fetchCatId">
                    <button type="submit" class="btn btn-outline-warning btn-sm" onclick="syncCatId('fetchCatId')">
                        <i class="fas fa-sync-alt"></i> Fetch
                    </button>
                </form>

                <form method="POST" action="{{ route('news-rss.clearCategory') }}" id="formClear" class="d-inline"
                      onsubmit="return confirmClear()">
                    @csrf
                    <input type="hidden" name="category_id" id="clearCatId">
                    <button type="submit" class="btn btn-outline-danger btn-sm" onclick="syncCatId('clearCatId')">
                        <i class="fas fa-trash-alt"></i> Clear + Refresh
                    </button>
                </form>
            </div>
        </div>
    </div>

    {{-- ── GROUPED BY CATEGORY ── --}}
    @forelse($grouped as $group)
        @php
            $category = $group['category'];
            $items    = $group['items'];
            $stats    = $group['stats'];
        @endphp

        <div class="card card-default mb-4" id="cat-{{ $category->id }}">
            {{-- Category Header --}}
            <div class="card-header d-flex align-items-center py-2"
                 style="background:linear-gradient(135deg,#1a3a4f,#1e5f74);color:#fff">
                <div class="flex-grow-1">
                    <strong style="font-size:1rem">
                        <i class="fas fa-folder-open mr-1 text-warning"></i>
                        {{ $category->name }}
                    </strong>
                    <span class="small text-white-50 ml-2">
                        {{ $items->pluck('news_web_id')->unique()->count() }} nguồn
                    </span>
                </div>
                <div class="d-flex align-items-center gap-2 mr-3">
                    @if($stats['pending'] > 0)
                        <span class="badge badge-warning">{{ $stats['pending'] }} pending</span>
                    @endif
                    @if($stats['done'] > 0)
                        <span class="badge badge-success">{{ $stats['done'] }} done</span>
                    @endif
                </div>
                <form method="POST" action="{{ route('news-rss.fetchByCategory') }}" class="d-inline">
                    @csrf
                    <input type="hidden" name="category_id" value="{{ $category->id }}">
                    <button class="btn btn-sm btn-outline-light">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                </form>
            </div>

            <div class="card-body p-0">
                @include('news-rss._table', ['items' => $items])
            </div>

            <div class="card-footer py-1 text-right small text-muted">
                Expires in ~{{ $items->first()?->expires_at?->diffForHumans() ?? '—' }}
            </div>
        </div>
    @empty
        <div class="card">
            <div class="card-body text-center py-5 text-muted">
                <i class="fas fa-rss fa-3x mb-3 d-block text-secondary"></i>
                <h5>Chưa có tin tức RSS</h5>
                <p>
                    Đảm bảo các nguồn <strong>News Web</strong> đã có <strong>RSS URL</strong>,
                    sau đó click <strong>Fetch All Sources</strong>.
                </p>
                <form method="POST" action="{{ route('news-rss.fetchAll') }}">
                    @csrf
                    <button class="btn btn-warning"><i class="fas fa-sync-alt"></i> Fetch Now</button>
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
    const id   = 'toast-' + Date.now();
    const bg   = type === 'success' ? '#28a745' : '#dc3545';
    const icon = type === 'success' ? 'fa-check-circle' : 'fa-times-circle';
    document.getElementById('toastWrap').insertAdjacentHTML('beforeend', `
        <div id="${id}" style="background:${bg};color:#fff;padding:12px 18px;border-radius:8px;
             margin-top:8px;box-shadow:0 4px 12px rgba(0,0,0,.25);display:flex;align-items:center;gap:10px;
             animation:fadeIn .2s ease">
            <i class="fas ${icon}"></i><span>${message}</span>
        </div>`);
    setTimeout(() => document.getElementById(id)?.remove(), 3500);
}

document.addEventListener('click', function(e) {
    const btn = e.target.closest('.btn-rss-save');
    if (!btn) return;

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

    fetch(btn.dataset.url, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' }
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showToast(data.message, 'success');
            const tr = btn.closest('tr');
            tr.classList.add('table-light');
            const statusTd = tr.querySelector('td:nth-last-child(2)');
            if (statusTd) statusTd.innerHTML = '<span class="badge badge-success">Done</span>';
            if (data.article_url) {
                const eyeBtn = tr.querySelector('.btn-outline-secondary.btn-xs:first-of-type, span.btn-xs');
                if (eyeBtn) eyeBtn.outerHTML = `<a href="${data.article_url}" class="btn btn-xs btn-outline-primary" title="Xem bài viết"><i class="fas fa-eye"></i></a>`;
            }
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

function syncCatId(targetId) {
    document.getElementById(targetId).value = document.getElementById('catActionSelect').value;
}

function confirmClear() {
    const sel = document.getElementById('catActionSelect');
    if (!sel.value) { alert('Vui lòng chọn category'); return false; }
    const name = sel.options[sel.selectedIndex].text;
    return confirm('Xóa toàn bộ items và fetch lại category "' + name + '"?');
}
</script>
@endsection
