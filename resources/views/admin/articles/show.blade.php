@extends('layouts.base', ['title' => $article->title])
@section('title', Str::limit($article->title, 60))
@section('content')
<div class="container-fluid">

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
                        <img src="{{ $article->thumbnail }}" class="img-fluid rounded mb-3" style="max-height:300px;object-fit:cover;width:100%">
                    @endif

                    @if($article->summary)
                        <div class="alert alert-info">
                            <strong>Summary:</strong> {{ $article->summary }}
                        </div>
                    @endif

                    <div class="article-content">
                        {!! $article->content !!}
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
                        <tr><th>Keyword</th><td>{{ $article->keyword->name ?? '—' }}</td></tr>
                        <tr><th>Source</th><td><a href="{{ $article->source_url }}" target="_blank">{{ $article->source_name }}</a></td></tr>
                        <tr><th>Viral Score</th>
                            <td><span class="badge badge-{{ $article->viral_score >= 80 ? 'danger' : ($article->viral_score >= 50 ? 'warning' : 'secondary') }}">{{ $article->viral_score }}</span></td></tr>
                        <tr><th>Slug</th><td><code class="small">{{ $article->slug }}</code></td></tr>
                        <tr><th>Published</th><td>{{ $article->published_at?->format('M d, H:i') ?? '—' }}</td></tr>
                        <tr><th>Expires</th><td>{{ $article->expires_at?->diffForHumans() ?? '—' }}</td></tr>
                        <tr><th>FAQ items</th><td>{{ $article->faq ? count($article->faq) : 0 }}</td></tr>
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
@endsection
