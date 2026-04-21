@extends('layouts.base', ['title' => 'Feed Sources'])
@section('title', 'Feed Sources')
@section('content')
<div class="container-fluid">

    {{-- ── HEADER ── --}}
    <div class="row align-items-center mb-3">
        <div class="col">
            <h4 class="mb-0">
                <i class="fas fa-rss text-warning"></i> Feed Sources
            </h4>
            <small class="text-muted">Quản lý nguồn RSS/Crawl theo category. Bài sẽ vào Feed Items trước, push sang Articles khi cần.</small>
        </div>
        <div class="col-auto d-flex gap-2">
            <a href="{{ route('feed-source.items') }}" class="btn btn-sm btn-outline-info">
                <i class="fas fa-list"></i> Feed Items
            </a>
            <form method="POST" action="{{ route('feed-source.fetchDue') }}" class="d-inline">
                @csrf
                <button class="btn btn-sm btn-warning">
                    <i class="fas fa-sync"></i> Fetch All Due
                </button>
            </form>
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

    {{-- ── ADD FORM ── --}}
    <div class="card card-outline card-primary mb-3">
        <div class="card-header py-2">
            <h6 class="card-title mb-0"><i class="fas fa-plus"></i> Thêm Feed Source</h6>
        </div>
        <div class="card-body py-2">
            <form method="POST" action="{{ route('feed-source.store') }}">
                @csrf
                <div class="row g-2">
                    @php $oldType = old('fetch_type', 'rss'); @endphp
                    <div class="col-md-2">
                        <label class="small mb-1">Category <span class="text-danger">*</span></label>
                        <select name="category_id" class="form-control form-control-sm" required>
                            <option value="">-- Chọn --</option>
                            @foreach($categories as $cat)
                                <option value="{{ $cat->id }}" {{ old('category_id') == $cat->id ? 'selected' : '' }}>
                                    {{ $cat->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="small mb-1">Tên <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control form-control-sm"
                               placeholder="ESPN NFL" value="{{ old('name') }}" required>
                    </div>
                    <div class="col-md-1">
                        <label class="small mb-1">Type <span class="text-danger">*</span></label>
                        <select name="fetch_type" class="form-control form-control-sm" id="addFetchType" required>
                            <option value="rss"          {{ $oldType === 'rss'          ? 'selected' : '' }}>RSS</option>
                            <option value="google_news"  {{ $oldType === 'google_news'  ? 'selected' : '' }}>Google News</option>
                            <option value="crawl"        {{ $oldType === 'crawl'        ? 'selected' : '' }}>Crawl</option>
                        </select>
                    </div>
                    <div class="col-md-3 {{ $oldType !== 'rss' ? 'd-none' : '' }}" id="addRssField">
                        <label class="small mb-1">RSS URL <span class="text-danger">*</span></label>
                        <input type="url" name="rss_url" class="form-control form-control-sm"
                               placeholder="https://...feed/" value="{{ old('rss_url') }}">
                    </div>
                    <div class="col-md-3 {{ $oldType !== 'google_news' ? 'd-none' : '' }}" id="addGoogleNewsField">
                        <label class="small mb-1">Keyword <span class="text-danger">*</span></label>
                        <input type="text" name="google_news_keyword" class="form-control form-control-sm"
                               placeholder="New England Patriots" value="{{ old('google_news_keyword') }}">
                        <small class="text-muted">Tự động build Google News RSS URL</small>
                    </div>
                    <div class="col-md-3 {{ $oldType !== 'crawl' ? 'd-none' : '' }}" id="addCrawlFields">
                        <label class="small mb-1">Crawl URL <span class="text-danger">*</span></label>
                        <input type="url" name="url" class="form-control form-control-sm"
                               placeholder="https://site.com/news/" value="{{ old('url') }}">
                    </div>
                    <div class="col-md-2 {{ $oldType !== 'crawl' ? 'd-none' : '' }}" id="addSelectorField">
                        <label class="small mb-1">Selector</label>
                        <input type="text" name="crawl_selector" class="form-control form-control-sm"
                               placeholder="h3 a, article a" value="{{ old('crawl_selector') }}">
                    </div>
                    <div class="col-md-1">
                        <label class="small mb-1">Interval (min)</label>
                        <input type="number" name="fetch_interval_minutes" class="form-control form-control-sm"
                               value="{{ old('fetch_interval_minutes', 60) }}" min="5" max="1440">
                    </div>
                    <div class="col-md-1 d-flex align-items-end">
                        <button type="submit" class="btn btn-sm btn-primary w-100">
                            <i class="fas fa-plus"></i> Add
                        </button>
                    </div>
                </div>
                @if($errors->any())
                    <div class="text-danger small mt-1">{{ $errors->first() }}</div>
                @endif
            </form>
        </div>
    </div>

    {{-- ── FILTER ── --}}
    <div class="mb-3">
        <a href="{{ route('feed-source.index') }}"
           class="btn btn-sm {{ !$categoryId ? 'btn-secondary' : 'btn-outline-secondary' }}">All</a>
        @foreach($categories as $cat)
            <a href="{{ route('feed-source.index', ['category_id' => $cat->id]) }}"
               class="btn btn-sm {{ $categoryId == $cat->id ? 'btn-primary' : 'btn-outline-primary' }} ml-1">
                {{ $cat->name }}
            </a>
        @endforeach
    </div>

    {{-- ── TABLE ── --}}
    @php $grouped = $sources->groupBy(fn($s) => $s->category->name ?? 'Unknown') @endphp

    @forelse($grouped as $catName => $items)
    <div class="card mb-3">
        <div class="card-header py-2 d-flex justify-content-between align-items-center">
            <span class="font-weight-bold"><i class="fas fa-tag text-primary mr-1"></i>{{ $catName }}</span>
            <div>
                <span class="badge badge-secondary mr-2">{{ $items->count() }} sources</span>
                <form method="POST" action="{{ route('feed-source.fetchByCategory') }}" class="d-inline">
                    @csrf
                    <input type="hidden" name="category_id" value="{{ $items->first()->category_id }}">
                    <button class="btn btn-xs btn-warning">
                        <i class="fas fa-sync"></i> Fetch All
                    </button>
                </form>
            </div>
        </div>
        <div class="card-body p-0">
            <table class="table table-sm table-hover mb-0">
                <thead class="thead-light">
                    <tr>
                        <th style="width:20%">Tên</th>
                        <th style="width:10%">Type</th>
                        <th style="width:30%">URL</th>
                        <th style="width:10%">Interval</th>
                        <th style="width:12%">Last Fetch</th>
                        <th style="width:8%">Total</th>
                        <th style="width:10%" class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($items as $source)
                    <tr class="{{ $source->is_active ? '' : 'table-secondary text-muted' }}">
                        <td class="small">{{ $source->name }}</td>
                        <td>
                            @if($source->fetch_type === 'rss')
                                <span class="badge badge-warning">RSS</span>
                            @elseif($source->fetch_type === 'google_news')
                                <span class="badge badge-danger">Google News</span>
                            @else
                                <span class="badge badge-info">Crawl</span>
                            @endif
                        </td>
                        <td class="small text-truncate" style="max-width:200px">
                            <a href="{{ $source->rss_url ?: $source->url }}" target="_blank" class="text-muted">
                                {{ $source->rss_url ?: $source->url }}
                            </a>
                        </td>
                        <td class="small">{{ $source->fetch_interval_minutes }}m</td>
                        <td class="small">
                            {{ $source->last_fetched_at?->setTimezone('Asia/Bangkok')->format('d/m H:i') ?? '—' }}
                        </td>
                        <td class="small text-center">{{ $source->total_fetched }}</td>
                        <td class="text-right">
                            <form method="POST" action="{{ route('feed-source.fetchOne', $source) }}" class="d-inline">
                                @csrf
                                <button class="btn btn-xs btn-success" title="Fetch ngay">
                                    <i class="fas fa-play"></i>
                                </button>
                            </form>
                            <button class="btn btn-xs btn-info ml-1"
                                    onclick="openEdit('{{ $source->id }}','{{ addslashes($source->name) }}','{{ $source->fetch_type }}','{{ $source->rss_url }}','{{ $source->url }}','{{ $source->crawl_selector }}','{{ $source->fetch_interval_minutes }}')">
                                <i class="fas fa-edit"></i>
                            </button>
                            <form method="POST" action="{{ route('feed-source.toggle', $source) }}" class="d-inline">
                                @csrf @method('PATCH')
                                <button class="btn btn-xs {{ $source->is_active ? 'btn-warning' : 'btn-outline-success' }} ml-1"
                                        title="{{ $source->is_active ? 'Tắt' : 'Bật' }}">
                                    <i class="fas {{ $source->is_active ? 'fa-pause' : 'fa-play-circle' }}"></i>
                                </button>
                            </form>
                            <form method="POST" action="{{ route('feed-source.destroy', $source) }}" class="d-inline"
                                  onsubmit="return confirm('Xóa {{ addslashes($source->name) }}?')">
                                @csrf @method('DELETE')
                                <button class="btn btn-xs btn-danger ml-1"><i class="fas fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @empty
        <div class="text-center text-muted py-5">
            <i class="fas fa-rss fa-3x mb-2"></i>
            <p>Chưa có feed source nào. Thêm một source ở trên.</p>
        </div>
    @endforelse

</div>

{{-- ── EDIT MODAL ── --}}
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="editForm">
                @csrf @method('PUT')
                <div class="modal-header py-2">
                    <h6 class="modal-title">Sửa Feed Source</h6>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label class="small">Tên <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="edit-name" class="form-control form-control-sm" required>
                    </div>
                    <div class="form-group">
                        <label class="small">Type</label>
                        <select name="fetch_type" id="edit-type" class="form-control form-control-sm">
                            <option value="rss">RSS</option>
                            <option value="crawl">Crawl</option>
                        </select>
                    </div>
                    <div class="form-group" id="editRssField">
                        <label class="small">RSS URL</label>
                        <input type="url" name="rss_url" id="edit-rss" class="form-control form-control-sm">
                    </div>
                    <div class="form-group" id="editCrawlField">
                        <label class="small">Crawl URL</label>
                        <input type="url" name="url" id="edit-url" class="form-control form-control-sm">
                    </div>
                    <div class="form-group">
                        <label class="small">Crawl Selector</label>
                        <input type="text" name="crawl_selector" id="edit-selector" class="form-control form-control-sm"
                               placeholder="h3 a, article a">
                    </div>
                    <div class="form-group mb-0">
                        <label class="small">Interval (phút)</label>
                        <input type="number" name="fetch_interval_minutes" id="edit-interval"
                               class="form-control form-control-sm" min="5" max="1440">
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-sm btn-secondary" data-dismiss="modal">Hủy</button>
                    <button type="submit" class="btn btn-sm btn-primary">Lưu</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Toggle add form fields
document.getElementById('addFetchType').addEventListener('change', function() {
    const v = this.value;
    document.getElementById('addRssField').classList.toggle('d-none',        v !== 'rss');
    document.getElementById('addGoogleNewsField').classList.toggle('d-none', v !== 'google_news');
    document.getElementById('addCrawlFields').classList.toggle('d-none',     v !== 'crawl');
    document.getElementById('addSelectorField').classList.toggle('d-none',   v !== 'crawl');
});

function openEdit(id, name, type, rssUrl, url, selector, interval) {
    document.getElementById('editForm').action = '/admin/feed-source/' + id;
    document.getElementById('edit-name').value     = name;
    document.getElementById('edit-type').value     = type;
    document.getElementById('edit-rss').value      = rssUrl;
    document.getElementById('edit-url').value      = url;
    document.getElementById('edit-selector').value = selector;
    document.getElementById('edit-interval').value = interval;

    const isRss = type === 'rss';
    document.getElementById('editRssField').style.display  = isRss ? '' : 'none';
    document.getElementById('editCrawlField').style.display = isRss ? 'none' : '';

    document.getElementById('edit-type').addEventListener('change', function() {
        const r = this.value === 'rss';
        document.getElementById('editRssField').style.display  = r ? '' : 'none';
        document.getElementById('editCrawlField').style.display = r ? 'none' : '';
    });

    $('#editModal').modal('show');
}
</script>
@endsection
