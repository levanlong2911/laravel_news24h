<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessKeywordJob;
use App\Models\Article;
use App\Models\Keyword;
use App\Services\Admin\SerpApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

class ArticleController extends Controller
{
    public function __construct(protected SerpApiService $serpApi) {}

    public function index(Request $request)
    {
        $status    = $request->get('status', 'all');
        $keywordId = $request->get('keyword_id');

        $articles = Article::with('keyword')
            ->when($status !== 'all', fn($q) => $q->where('status', $status))
            ->when($keywordId,        fn($q) => $q->where('keyword_id', $keywordId))
            ->orderByDesc('viral_score')
            ->orderByDesc('created_at')
            ->paginate(20);

        $keywords = Keyword::where('is_active', true)->orderBy('sort_order')->get();
        return view("admin.articles.index", [
            "route" => "article",
            "action" => "article-index",
            "menu" => "menu-open",
            "active" => "active",
            'articles' => $articles,
            'keywords' => $keywords,
            'status' => $status,
            'keywordId' => $keywordId
        ]);
    }

    public function show(Article $article)
    {
        // return view('admin.articles.show', compact('article'));
        return view("admin.articles.show", [
            "route" => "article",
            "action" => "article-show",
            "menu" => "menu-open",
            "active" => "active",
            'article' => $article,
        ]);
    }

    public function publish(Article $article)
    {
        $article->update(['status' => 'published', 'published_at' => now()]);
        return back()->with('success', "Published: {$article->title}");
    }

    public function unpublish(Article $article)
    {
        $article->update(['status' => 'pending', 'published_at' => null]);
        return back()->with('success', 'Unpublished');
    }

    public function destroy(Article $article)
    {
        $article->delete();
        return back()->with('success', 'Deleted');
    }

    public function publishAll(Request $request)
    {
        $count = Article::where('status', 'pending')
            ->when($request->keyword_id, fn($q) => $q->where('keyword_id', $request->keyword_id))
            ->update(['status' => 'published', 'published_at' => now()]);

        return back()->with('success', "Published {$count} articles");
    }

    // Dispatch tất cả active keywords vào queue
    public function generateAll(Request $request)
    {
        $keywords = Keyword::where('is_active', true)->get();

        foreach ($keywords as $keyword) {
            ProcessKeywordJob::dispatch($keyword)->onQueue('articles');
        }

        return back()->with('success', "Dispatched {$keywords->count()} keywords to queue");
    }

    // Dispatch 1 keyword cụ thể
    public function generateOne(Request $request)
    {
        $keyword = Keyword::find($request->get('keyword_id'));

        if (!$keyword) {
            return back()->with('error', 'Keyword not found');
        }

        ProcessKeywordJob::dispatch($keyword)->onQueue('articles');

        return back()->with('success', "Dispatched: {$keyword->name}");
    }

    public function clearCache()
    {
        Cache::flush();
        return back()->with('success', 'Cache cleared');
    }
}
