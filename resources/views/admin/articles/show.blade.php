@extends('layouts.base', ['title' => $article->title])
@section('title', Str::limit($article->title, 60))
@section('content')
<div class="container-fluid">

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ session('success') }}
            <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
        </div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            {{ session('error') }}
            <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
        </div>
    @endif

    <div class="row mb-3">
        <div class="col">
            <a href="{{ route('article.index') }}" class="btn btn-sm btn-outline-secondary">
                <i class="fas fa-arrow-left"></i> Back
            </a>
        </div>
        <div class="col-auto">
            @if($article->status === 'published')
                <form method="POST" action="{{ route('article.unpublish', $article) }}" class="d-inline">
                    @csrf
                    <button class="btn btn-sm btn-warning">Unpublish</button>
                </form>
            @else
                <form method="POST" action="{{ route('article.publish', $article) }}" class="d-inline">
                    @csrf
                    <button class="btn btn-sm btn-success">Publish</button>
                </form>
            @endif
            <button type="button" class="btn btn-sm btn-secondary" id="btn-find-image"
                    data-url="{{ route('article.searchImages', $article) }}"
                    data-update-url="{{ route('article.updateThumbnail', $article) }}">
                <i class="fas fa-images"></i> Tìm ảnh
            </button>
            <form method="POST" action="{{ route('article.destroy', $article) }}" class="d-inline" onsubmit="return confirm('Delete this article?')">
                @csrf @method('DELETE')
                <button class="btn btn-sm btn-danger">Delete</button>
            </form>
        </div>
    </div>

    <div class="row">
        {{-- Main content --}}
        <div class="col-md-8">
            <div class="card card-default">
                <div class="card-header">
                    <h5 class="mb-0">{{ $article->title }}</h5>
                </div>
                <div class="card-body">
                    @if($article->thumbnail)
                        <img id="article-thumbnail" src="{{ $article->thumbnail }}" class="img-fluid rounded mb-3" style="max-height:300px;object-fit:cover;width:100%">
                    @endif

                    @if($article->summary)
                        <div class="alert alert-info">
                            <strong>Summary:</strong> {{ $article->summary }}
                        </div>
                    @endif

                    <style>.article-content p{margin-bottom:1.2em;line-height:1.75}.article-content img{max-width:100%;height:auto;display:block;margin:1rem auto;border-radius:6px}</style>
                    <div class="article-content">
                        @php
                            $content = $article->content ?? '';
                            if ($content && !str_contains($content, '<')) {
                                $content = collect(explode("\n\n", $content))
                                    ->filter(fn($p) => trim($p) !== '')
                                    ->map(fn($p) => '<p>' . e(trim($p)) . '</p>')
                                    ->implode("\n");
                            }
                        @endphp
                        {!! $content !!}
                    </div>

                    {{-- FAQ --}}
                    @if($article->faq && count($article->faq) > 0)
                        <hr>
                        <h5>Frequently Asked Questions</h5>
                        <div class="accordion" id="faqAccordion">
                            @foreach($article->faq as $i => $item)
                                <div class="card mb-1">
                                    <div class="card-header p-0">
                                        <button class="btn btn-link btn-block text-left px-3 py-2" type="button"
                                            data-toggle="collapse" data-target="#faq{{ $i }}">
                                            {{ $item['question'] }}
                                        </button>
                                    </div>
                                    <div id="faq{{ $i }}" class="collapse">
                                        <div class="card-body small">{{ $item['answer'] }}</div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Sidebar --}}
        <div class="col-md-4">
            <div class="card card-default">
                <div class="card-header"><strong>Article Info</strong></div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <tr><th>Status</th>
                            <td><span class="badge badge-{{ ['published'=>'success','processing'=>'warning','pending'=>'info','failed'=>'danger'][$article->status] ?? 'secondary' }}">{{ $article->status }}</span></td></tr>
                        <tr><th>Keyword</th><td>{{ $article->keyword?->name ?? '—' }}</td></tr>
                        <tr><th>Source</th><td><a href="{{ $article->source_url }}" target="_blank">{{ $article->source_name }}</a></td></tr>
                        <tr><th>Viral Score</th>
                            <td><span class="badge badge-{{ $article->viral_score >= 80 ? 'danger' : ($article->viral_score >= 50 ? 'warning' : 'secondary') }}">{{ $article->viral_score }}</span></td></tr>
                        <tr><th>Slug</th><td><code class="small">{{ $article->slug }}</code></td></tr>
                        <tr><th>Published</th><td>{{ $article->published_at?->format('M d, H:i') ?? '—' }}</td></tr>
                        <tr><th>Expires</th><td>{{ $article->expires_at?->diffForHumans() ?? '—' }}</td></tr>
                        <tr><th>FAQ items</th><td>{{ $article->faq ? count($article->faq) : 0 }}</td></tr>
                        @if($article->post_id)
                        <tr><th>Post</th>
                            <td><a href="{{ route('post.update', $article->post_id) }}" target="_blank" class="btn btn-xs btn-success">
                                <i class="fas fa-newspaper mr-1"></i>Xem bài viết
                            </a></td></tr>
                        @endif
                    </table>
                </div>
            </div>

            <div class="card card-default">
                <div class="card-header"><strong>SEO</strong></div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="small font-weight-bold text-muted">META TITLE</label>
                        <div class="p-2 bg-light rounded small">{{ $article->title }}</div>
                        <div class="text-right small text-{{ strlen($article->title) > 70 ? 'danger' : 'success' }}">
                            {{ strlen($article->title) }} chars
                        </div>
                    </div>
                    <div>
                        <label class="small font-weight-bold text-muted">META DESCRIPTION</label>
                        <div class="p-2 bg-light rounded small">{{ $article->meta_description ?: '—' }}</div>
                        <div class="text-right small text-{{ strlen($article->meta_description ?? '') > 160 ? 'danger' : 'success' }}">
                            {{ strlen($article->meta_description ?? '') }} chars
                        </div>
                    </div>
                </div>
            </div>

            <div class="card card-default">
                <div class="card-header"><strong>Original Source</strong></div>
                <div class="card-body small">
                    <p><strong>Title:</strong> {{ $article->source_title }}</p>
                    <a href="{{ $article->source_url }}" target="_blank" class="btn btn-sm btn-outline-secondary btn-block">
                        <i class="fas fa-external-link-alt"></i> View Original
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Modal tìm ảnh --}}
<div class="modal fade" id="imageSearchModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-images mr-2"></i>Tìm ảnh — <span id="img-search-query" class="text-muted small"></span></h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <div id="img-loading" class="text-center py-5">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p class="mt-2 text-muted">Đang tìm kiếm hình ảnh...</p>
                </div>
                <div id="img-grid" class="row" style="display:none"></div>
                <div id="img-empty" class="text-center py-4 text-muted" style="display:none">
                    <i class="fas fa-image fa-3x mb-2"></i>
                    <p>Không tìm thấy ảnh phù hợp (≥1200px).</p>
                </div>
            </div>
            <div class="modal-footer">
                <small class="text-muted mr-auto">Click vào ảnh để chọn làm thumbnail bài viết</small>
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Đóng</button>
            </div>
        </div>
    </div>
</div>

@section('script')
<script>
(function () {
    const btn       = document.getElementById('btn-find-image');
    const searchUrl = btn.dataset.url;
    const updateUrl = btn.dataset.updateUrl;
    const modal     = $('#imageSearchModal');
    const grid      = document.getElementById('img-grid');
    const loading   = document.getElementById('img-loading');
    const empty     = document.getElementById('img-empty');
    let cachedImages = null;

    btn.addEventListener('click', function () {
        modal.modal('show');

        if (cachedImages) {
            renderImages(cachedImages);
            return;
        }

        grid.style.display    = 'none';
        empty.style.display   = 'none';
        loading.style.display = 'block';
        grid.innerHTML        = '';

        fetch(searchUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(data => {
                loading.style.display = 'none';
                document.getElementById('img-search-query').textContent = data.query || '';
                cachedImages = data.images || [];
                renderImages(cachedImages);
            })
            .catch(() => {
                loading.style.display = 'none';
                empty.style.display   = 'block';
            });
    });

    function renderImages(images) {
        if (!images.length) { empty.style.display = 'block'; return; }

        grid.style.display = '';
        grid.innerHTML     = '';

        images.forEach(img => {
            const col  = document.createElement('div');
            col.className = 'col-md-3 col-sm-4 col-6 mb-3';

            const card = document.createElement('div');
            card.className = 'border rounded overflow-hidden';
            card.style.cssText = 'cursor:pointer';

            const image = document.createElement('img');
            image.src   = img.thumbnail;
            image.className = 'img-fluid w-100';
            image.style.cssText = 'height:140px;object-fit:cover';
            image.onerror = () => col.remove();

            const info = document.createElement('div');
            info.className = 'p-1 bg-light';

            const src = document.createElement('small');
            src.className = 'text-muted d-block text-truncate';
            src.textContent = img.source || '';

            const size = document.createElement('small');
            size.className = 'text-success';
            size.textContent = img.width + '×' + img.height;

            info.appendChild(src);
            info.appendChild(size);
            card.appendChild(image);
            card.appendChild(info);
            col.appendChild(card);
            grid.appendChild(col);

            card.addEventListener('click', () => selectImage(img.url, card));
        });
    }

    function selectImage(url, card) {
        if (!confirm('Dùng ảnh này làm thumbnail bài viết?')) return;

        card.style.opacity = '0.5';

        fetch(updateUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify({ thumbnail: url }),
        })
        .then(r => r.json())
        .then(data => {
            if (!data.success) { card.style.opacity = '1'; return; }

            const thumb = document.getElementById('article-thumbnail');
            if (thumb) {
                thumb.src = data.thumbnail;
            } else {
                const img = document.createElement('img');
                img.id        = 'article-thumbnail';
                img.src       = data.thumbnail;
                img.className = 'img-fluid rounded mb-3';
                img.style.cssText = 'max-height:300px;object-fit:cover;width:100%';
                document.querySelector('.card-body').prepend(img);
            }

            modal.modal('hide');
        })
        .catch(() => { card.style.opacity = '1'; });
    }
})();
</script>
@endsection
@endsection
