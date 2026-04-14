@extends('layouts.base', ['title' => __('post.list_post')])
@section('title', __('post.list_post'))
@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0">Trending 24h</h4>
        <small class="text-muted">Google Trends + SerpAPI</small>
    </div>
    {{-- <form method="POST" action="{{ route('admin.articles.generate') }}"> --}}
    <form method="POST" action="">
        @csrf
        <input type="hidden" name="geo" value="{{ $geo }}">
        <button class="btn btn-primary btn-sm">+ Tạo bài tự động</button>
    </form>
</div>

{{-- Error --}}
@if($error)
    <div class="alert alert-warning">{{ $error }}</div>
@endif

{{-- Filter --}}
<form method="GET" class="card card-body mb-4 p-3">
    <div class="row g-2 align-items-end">
        <div class="col-auto">
            <label class="form-label mb-1 small fw-semibold">Khu vực</label>
            <select name="geo" class="form-select form-select-sm">
                @foreach(['US' => '🇺🇸 United States', 'GB' => '🇬🇧 United Kingdom', 'AU' => '🇦🇺 Australia', 'CA' => '🇨🇦 Canada'] as $code => $label)
                    <option value="{{ $code }}" {{ $geo === $code ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-auto">
            <button class="btn btn-outline-primary btn-sm">Tìm kiếm</button>
        </div>
        <div class="col-auto ms-auto text-muted small">
            {{ count($articles) }} bài / {{ count($topics) }} trending topics
        </div>
    </div>
</form>

{{-- Trending topics pills --}}
@if(count($topics) > 0)
<div class="mb-3 d-flex flex-wrap gap-2">
    @foreach(array_slice($topics, 0, 10) as $topic)
        <span class="badge bg-light text-dark border" style="font-size:12px">
            {{ $topic['keyword'] }}
            <span class="text-muted ms-1">{{ $topic['raw'] }}</span>
        </span>
    @endforeach
</div>
@endif

{{-- Articles table --}}
@if(count($articles) > 0)
@php $maxTraffic = collect($articles)->max('trend_traffic') ?: 1; @endphp
<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width:36px">#</th>
                    <th>Bài viết</th>
                    <th style="width:130px">Nguồn</th>
                    <th style="width:130px">Keyword</th>
                    <th style="width:100px">Traffic</th>
                    <th style="width:70px">Score</th>
                    <th style="width:90px">Thời gian</th>
                    <th style="width:130px">Hành động</th>
                </tr>
            </thead>
            <tbody>
                @foreach($articles as $i => $article)
                <tr>
                    <td class="text-muted small">{{ $i + 1 }}</td>

                    <td>
                        <a href="{{ $article['link'] }}" target="_blank"
                           class="fw-semibold text-dark text-decoration-none d-block">
                            {{ $article['title'] }}
                        </a>
                        @if(!empty($article['snippet']))
                            <div class="text-muted small mt-1">
                                {{ Str::limit($article['snippet'], 100) }}
                            </div>
                        @endif
                        @if(!empty($article['top_story']))
                            <span class="badge bg-danger mt-1" style="font-size:10px">Top Story</span>
                        @endif
                    </td>

                    <td class="small">{{ $article['source'] ?? $article['domain'] ?? '—' }}</td>

                    <td>
                        <span class="badge bg-secondary" style="font-size:11px">
                            {{ $article['trend_keyword'] ?? '—' }}
                        </span>
                    </td>

                    <td>
                        <div class="small fw-semibold">{{ number_format($article['trend_traffic'] ?? 0) }}</div>
                        <div class="traffic-bar">
                            <div class="traffic-bar-fill"
                                 style="width:{{ round(($article['trend_traffic'] / $maxTraffic) * 100) }}%">
                            </div>
                        </div>
                    </td>

                    <td>
                        @php $s = $article['quality_score'] ?? 0; @endphp
                        <span class="badge {{ $s >= 100 ? 'score-hi' : ($s >= 60 ? 'score-mid' : 'score-lo') }}"
                              style="min-width:40px; text-align:center">
                            {{ $s }}
                        </span>
                    </td>

                    <td class="small text-muted">{{ $article['date'] ?? '—' }}</td>

                    <td>
                        <div class="d-flex gap-1">
                            <a href="{{ $article['link'] }}" target="_blank"
                               class="btn btn-outline-secondary btn-sm">Xem</a>
                            <form method="POST" action="{{ route('admin.articles.generate') }}">
                                @csrf
                                <input type="hidden" name="keyword" value="{{ $article['trend_keyword'] }}">
                                <input type="hidden" name="geo"     value="{{ $geo }}">
                                <button class="btn btn-success btn-sm"
                                        onclick="return confirm('Tạo bài: {{ addslashes($article['trend_keyword']) }}?')">
                                    Tạo bài
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

@else
<div class="card card-body text-center text-muted py-5">
    Không tìm được bài nào trong 24h qua.
</div>
@endif
@endsection
