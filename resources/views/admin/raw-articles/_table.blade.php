<table class="table table-sm table-bordered table-hover mb-0">
    <thead style="background:#f4f6f9">
        <tr class="text-center small text-uppercase text-muted" style="font-size:.75rem">
            <th width="30">#</th>
            <th width="55">Thumb</th>
            <th class="text-left">Title</th>
            <th width="55">Score</th>
            <th width="55">FB</th>
            <th width="50">Top</th>
            <th width="55">Stories</th>
            <th width="90">Posted</th>
            <th width="75">Status</th>
            <th width="120">Actions</th>
        </tr>
    </thead>
    <tbody>
    @foreach($items as $i => $raw)
        @php $isRecommended = isset($recommendedId) && $raw->id === $recommendedId; @endphp
        <tr class="{{ $raw->status==='done' ? 'table-light' : '' }}"
            style="{{ $isRecommended ? 'border-left:3px solid #ffc107;background:#fffdf0' : '' }}">

            <td class="text-center align-middle text-muted small font-weight-bold">{{ $i + 1 }}</td>

            <td class="text-center align-middle p-1">
                @if($raw->thumbnail)
                    <img src="{{ $raw->thumbnail }}" width="52" height="36"
                         style="object-fit:cover;border-radius:4px;display:block;margin:auto"
                         onerror="this.style.display='none'">
                @elseif($raw->source_icon)
                    <img src="{{ $raw->source_icon }}" width="20" height="20"
                         style="border-radius:3px;display:block;margin:auto"
                         onerror="this.style.display='none'">
                @else
                    <div style="width:52px;height:36px;background:#eee;border-radius:4px;margin:auto;display:flex;align-items:center;justify-content:center">
                        <i class="fas fa-image text-muted" style="font-size:12px"></i>
                    </div>
                @endif
            </td>

            <td class="align-middle" style="max-width:0">
                <a href="{{ $raw->url }}" target="_blank"
                   class="d-block font-weight-bold text-dark text-truncate"
                   title="{{ $raw->title }}" style="max-width:100%">
                    @if($isRecommended)
                        <span class="badge badge-warning mr-1" style="font-size:.7em;vertical-align:middle">⭐ Đề xuất</span>
                    @endif
                    {{ $raw->title }}
                </a>
                <div class="small text-muted text-truncate" style="max-width:100%">
                    <span class="text-primary font-weight-bold">{{ $raw->source }}</span>
                    @if($raw->snippet)
                        &nbsp;·&nbsp;{{ Str::limit($raw->snippet, 90) }}
                    @endif
                </div>
            </td>

            <td class="text-center align-middle">
                @php $sc = $raw->viral_score; @endphp
                <span class="badge badge-pill badge-{{ $sc >= 100 ? 'danger' : ($sc >= 70 ? 'warning' : ($sc >= 40 ? 'info' : 'secondary')) }}"
                      style="font-size:.8em;padding:4px 7px">{{ $sc }}</span>
            </td>

            <td class="text-center align-middle">
                @php $fb = $raw->fb_score; @endphp
                @if($fb >= 80)
                    <span class="badge badge-pill badge-danger" style="font-size:.8em;padding:4px 7px"
                          title="High FB viral potential">🔥 {{ $fb }}</span>
                @elseif($fb >= 50)
                    <span class="badge badge-pill badge-warning" style="font-size:.8em;padding:4px 7px">{{ $fb }}</span>
                @elseif($fb >= 25)
                    <span class="badge badge-pill badge-info" style="font-size:.8em;padding:4px 7px">{{ $fb }}</span>
                @else
                    <span class="badge badge-pill badge-secondary" style="font-size:.8em;padding:4px 7px">{{ $fb }}</span>
                @endif
            </td>

            <td class="text-center align-middle">
                @if($raw->top_story)
                    <span title="Google News Top Story" style="font-size:1.1rem">🔥</span>
                @else
                    <span class="text-muted">—</span>
                @endif
            </td>

            <td class="text-center align-middle">
                <span class="font-weight-bold {{ $raw->stories_count >= 5 ? 'text-danger' : ($raw->stories_count >= 2 ? 'text-warning' : 'text-muted') }}">
                    {{ $raw->stories_count }}
                </span>
            </td>

            <td class="text-center align-middle small text-muted" style="white-space:nowrap"
                title="{{ $raw->published_date }}">
                {{ $raw->posted_ago }}
            </td>

            <td class="text-center align-middle">
                @if($raw->status === 'pending')
                    <span class="badge badge-warning">Pending</span>
                @elseif($raw->status === 'done')
                    <span class="badge badge-success">Done</span>
                @else
                    <span class="badge badge-secondary">{{ $raw->status }}</span>
                @endif
            </td>

            <td class="text-center align-middle" style="white-space:nowrap">
                @if($raw->status === 'done' && $raw->article)
                    <a href="{{ route('article.show', $raw->article) }}" class="btn btn-xs btn-outline-primary" title="View Article">
                        <i class="fas fa-eye"></i>
                    </a>
                @else
                    <span class="btn btn-xs btn-outline-secondary" style="opacity:.35;cursor:default">
                        <i class="fas fa-eye"></i>
                    </span>
                @endif
                <a href="{{ $raw->url }}" target="_blank"
                   class="btn btn-xs btn-outline-secondary" title="Source">
                    <i class="fas fa-external-link-alt"></i>
                </a>
                <button class="btn btn-xs btn-save-article {{ $raw->status === 'done' ? 'btn-outline-primary' : 'btn-primary' }}"
                        data-url="{{ route('raw-article.save', $raw) }}"
                        title="{{ $raw->status === 'done' ? 'Tải lại (bài mới)' : 'Tải & lưu bài viết' }}">
                    <i class="fas fa-download"></i>
                </button>
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
