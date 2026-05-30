<table class="table table-sm table-bordered table-hover mb-0">
    <thead style="background:#f4f6f9">
        <tr class="text-center small text-uppercase text-muted" style="font-size:.75rem">
            <th width="30">#</th>
            <th width="60">Image</th>
            <th class="text-left">Title</th>
            <th width="110">Website</th>
            <th width="80">Posted</th>
            <th width="75">Status</th>
            <th width="120">Actions</th>
        </tr>
    </thead>
    <tbody>
    @foreach($items as $i => $item)
        <tr class="{{ $item->status === 'done' ? 'table-light' : '' }}">

            <td class="text-center align-middle text-muted small font-weight-bold">{{ $i + 1 }}</td>

            <td class="text-center align-middle p-1">
                @if($item->image)
                    <img src="{{ $item->image }}" width="52" height="36"
                         style="object-fit:cover;border-radius:4px;display:block;margin:auto"
                         onerror="this.style.display='none'">
                @else
                    <div style="width:52px;height:36px;background:#eee;border-radius:4px;margin:auto;display:flex;align-items:center;justify-content:center">
                        <i class="fas fa-image text-muted" style="font-size:12px"></i>
                    </div>
                @endif
            </td>

            <td class="align-middle" style="max-width:0">
                <a href="{{ $item->url }}" target="_blank"
                   class="d-block font-weight-bold text-dark text-truncate"
                   title="{{ $item->title }}" style="max-width:100%">
                    {{ $item->title }}
                </a>
                @if($item->description)
                    <div class="small text-muted text-truncate" style="max-width:100%">
                        {{ $item->description }}
                    </div>
                @endif
            </td>

            <td class="align-middle small" style="white-space:nowrap">
                <span class="text-muted">{{ $item->newsWeb->domain }}</span>
            </td>

            <td class="text-center align-middle small text-muted" style="white-space:nowrap"
                title="{{ $item->published_at?->format('Y-m-d H:i') }}">
                {{ $item->published_at?->diffForHumans() ?? '—' }}
            </td>

            <td class="text-center align-middle">
                @if($item->status === 'pending')
                    <span class="badge badge-warning">Pending</span>
                @else
                    <span class="badge badge-success">Done</span>
                @endif
            </td>

            <td class="text-center align-middle" style="white-space:nowrap">
                @if($item->status === 'done' && $item->article)
                    <a href="{{ route('article.show', $item->article) }}"
                       class="btn btn-xs btn-outline-primary" title="Xem bài viết">
                        <i class="fas fa-eye"></i>
                    </a>
                @else
                    <span class="btn btn-xs btn-outline-secondary" style="opacity:.35;cursor:default">
                        <i class="fas fa-eye"></i>
                    </span>
                @endif

                <a href="{{ $item->url }}" target="_blank"
                   class="btn btn-xs btn-outline-secondary" title="Nguồn gốc">
                    <i class="fas fa-external-link-alt"></i>
                </a>

                <button class="btn btn-xs btn-rss-save {{ $item->status === 'done' ? 'btn-outline-primary' : 'btn-primary' }}"
                        data-url="{{ route('news-rss.save', $item) }}"
                        title="{{ $item->status === 'done' ? 'Crawl lại (bài mới)' : 'Crawl & lưu bài viết' }}">
                    <i class="fas fa-download"></i>
                </button>

                <form method="POST" action="{{ route('news-rss.destroy', $item) }}"
                      class="d-inline" onsubmit="return confirm('Xóa bài này?')">
                    @csrf @method('DELETE')
                    <button class="btn btn-xs btn-outline-danger" title="Xóa">
                        <i class="fas fa-trash"></i>
                    </button>
                </form>
            </td>
        </tr>
    @endforeach
    </tbody>
</table>
