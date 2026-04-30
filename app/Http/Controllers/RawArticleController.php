<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\Keyword;
use App\Models\RawArticle;
use App\Services\Admin\ArticleCrawlerService;
use App\Services\Admin\FetchKeywordNewsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class RawArticleController extends Controller
{
    public function index(Request $request)
    {
        $keywordId = $request->get('keyword_id');
        $status    = $request->get('status', 'all');

        $keywords = Keyword::where('is_active', true)->orderBy('sort_order')->get();
        $kwIds    = $keywordId ? [$keywordId] : $keywords->pluck('id')->all();

        $allArticles = RawArticle::with('article')
            ->whereIn('keyword_id', $kwIds)
            ->when($status !== 'all', fn($q) => $q->where('status', $status))
            ->orderByDesc('viral_score')
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('keyword_id');

        $kwList  = $keywordId ? $keywords->where('id', $keywordId) : $keywords;
        $grouped = $kwList->map(function ($kw) use ($allArticles) {
            $all    = $allArticles->get($kw->id, collect());
            $top    = $all->where('list_type', 'top')
                ->sortByDesc(fn($a) => $a->viral_score * 0.6 + $a->fb_score * 0.4)
                ->take(10)->values();
            $recent = $all->where('list_type', 'recent')
                ->sortByDesc(fn($a) => $a->published_timestamp)
                ->take(20)->values();

            $combined = $top->merge($recent);

            if ($combined->isEmpty()) return null;

            return [
                'keyword'     => $kw,
                'top'         => $top,
                'recent'      => $recent,
                'recommended' => $this->buildRecommendation($top->first()),
                'stats'       => [
                    'total'   => $combined->count(),
                    'pending' => $combined->where('status', 'pending')->count(),
                    'done'    => $combined->where('status', 'done')->count(),
                ],
            ];
        })->filter()->values();

        return view('admin.raw-articles.index', [
            'route'     => 'raw-article',
            'action'    => 'raw-article-index',
            'menu'      => 'menu-open',
            'active'    => 'active',
            'grouped'   => $grouped,
            'keywords'  => $keywords,
            'keywordId' => $keywordId,
            'status'    => $status,
        ]);
    }

    public function fetchAll(FetchKeywordNewsService $fetchService)
    {
        $keywords = Keyword::where('is_active', true)->get();

        RawArticle::where('status', '!=', 'generating')->delete();

        foreach ($keywords as $kw) {
            $queries = array_filter(array_merge(
                [!empty($kw->search_keyword) ? $kw->search_keyword : ($kw->name . ' news')],
                $kw->extra_queries ?? []
            ));
            foreach ($queries as $q) {
                Cache::forget('serp_news_v2_' . md5($q));
            }
        }

        foreach ($keywords as $kw) {
            $fetchService->fetch($kw);
        }

        return back()->with('success', "Đã xóa data cũ và fetch {$keywords->count()} keywords mới.");
    }

    public function fetchOne(Request $request, FetchKeywordNewsService $fetchService)
    {
        $keyword = Keyword::find($request->get('keyword_id'));

        if (!$keyword) {
            return back()->with('error', 'Keyword not found');
        }

        $key = 'fetch-keyword-' . $keyword->id;
        if (RateLimiter::tooManyAttempts($key, 1)) {
            $seconds = RateLimiter::availableIn($key);
            return back()->with('error', "Please wait {$seconds}s before fetching {$keyword->name} again.");
        }
        RateLimiter::hit($key, 30);

        RawArticle::where('keyword_id', $keyword->id)
            ->whereIn('status', ['pending', 'failed'])
            ->delete();

        $queries = array_filter(array_merge(
            [!empty($keyword->search_keyword) ? $keyword->search_keyword : ($keyword->name . ' news')],
            $keyword->extra_queries ?? []
        ));
        foreach ($queries as $q) {
            Cache::forget('serp_news_v2_' . md5($q));
        }

        $result = $fetchService->fetch($keyword);

        return back()->with('success', "Fetched {$keyword->name}: {$result['saved']} new (top={$result['top']}, recent={$result['recent']}).");
    }

    public function save(Request $request, RawArticle $rawArticle, ArticleCrawlerService $crawler)
    {
        $urlHash = md5($rawArticle->url);

        Cache::forget('crawl:' . $urlHash);

        $contents = $crawler->crawlMany([$rawArticle->url]);
        $content  = trim($contents[$rawArticle->url] ?? '');

        if (strlen($content) < 100) {
            return back()->with('error', 'Không crawl được nội dung bài viết.');
        }

        $title = $rawArticle->title;
        $slug  = $this->uniqueSlug(Str::slug($title ?: 'article'));

        $finalUrlHash = Article::where('source_url_hash', $urlHash)->exists()
            ? md5($rawArticle->url . '_' . time())
            : $urlHash;

        $article = Article::create([
            'keyword_id'      => $rawArticle->keyword_id,
            'category_id'     => $rawArticle->keyword->category_id ?? null,
            'source_url'      => $rawArticle->url,
            'source_url_hash' => $finalUrlHash,
            'source_title'    => $title,
            'source_name'     => $rawArticle->source,
            'thumbnail'       => $rawArticle->thumbnail,
            'title'           => $title,
            'slug'            => $slug,
            'content'         => $content,
            'viral_score'     => $rawArticle->viral_score,
            'status'          => 'pending',
            'expires_at'      => now()->addHours(48),
            'crawled_by'      => auth()->id(),
        ]);

        $rawArticle->update(['status' => 'done', 'article_id' => $article->id]);

        return back()->with('success', 'Đã lưu bài viết: ' . $title);
    }

    public function clearRefetch(Request $request, FetchKeywordNewsService $fetchService)
    {
        $keyword = Keyword::find($request->get('keyword_id'));

        if (!$keyword) {
            return back()->with('error', 'Keyword not found');
        }

        $deleted = RawArticle::where('keyword_id', $keyword->id)
            ->where('status', '!=', 'generating')
            ->delete();

        $queries = array_filter(array_merge(
            [!empty($keyword->search_keyword) ? $keyword->search_keyword : ($keyword->name . ' news')],
            $keyword->extra_queries ?? []
        ));
        foreach ($queries as $q) {
            Cache::forget('serp_news_v2_' . md5($q));
        }

        $result = $fetchService->fetch($keyword);

        return back()->with('success', "Cleared {$deleted} + fetched {$result['saved']} new for {$keyword->name}.");
    }

    public function destroy(RawArticle $rawArticle)
    {
        $rawArticle->delete();
        return back()->with('success', 'Deleted.');
    }

    private function buildRecommendation(?\App\Models\RawArticle $article): ?array
    {
        if (!$article) return null;

        $reasons    = [];
        $title      = $article->title ?? '';
        $titleLower = strtolower($title);

        if ($article->topic_score > 0) {
            $reasons[] = ['icon' => '🔥', 'text' => "Topic đang trending (topic score: {$article->topic_score})"];
        }
        if ($article->stories_count >= 3) {
            $reasons[] = ['icon' => '📰', 'text' => "{$article->stories_count} nguồn khác nhau đang cover cùng chủ đề"];
        } elseif ($article->stories_count >= 1) {
            $reasons[] = ['icon' => '📰', 'text' => "{$article->stories_count} nguồn đang cover chủ đề này"];
        }
        if ($article->fb_score >= 35) {
            $reasons[] = ['icon' => '💥', 'text' => "FB score rất cao ({$article->fb_score}/100) — title có hook mạnh"];
        } elseif ($article->fb_score >= 25) {
            $reasons[] = ['icon' => '👍', 'text' => "FB score tốt ({$article->fb_score}/100) — tiềm năng viral"];
        }
        if ($article->viral_score >= 90) {
            $reasons[] = ['icon' => '⚡', 'text' => "SerpAPI score cao ({$article->viral_score}) — bài mới + top Google News"];
        }
        if (preg_match("/['\"][^'\"]{2,}['\"]/", $title)) {
            $reasons[] = ['icon' => '🤔', 'text' => 'Title có quote/nickname → curiosity gap'];
        }
        if (str_contains($title, '?')) {
            $reasons[] = ['icon' => '❓', 'text' => 'Title dạng câu hỏi → kích thích tò mò'];
        }
        if (preg_match('/\b\d+\b/', $title)) {
            $reasons[] = ['icon' => '🔢', 'text' => 'Title có con số cụ thể → tăng credibility'];
        }
        if (preg_match('/breaking|exclusive|just in/i', $titleLower)) {
            $reasons[] = ['icon' => '🚨', 'text' => 'Breaking news → urgency cao'];
        }
        if (preg_match('/\b(top|best|worst|never|only|biggest|greatest)\b/i', $titleLower)) {
            $reasons[] = ['icon' => '🏆', 'text' => 'Title có superlative → highly shareable'];
        }
        if (preg_match('/\b(should|must|why|how)\b/i', $titleLower)) {
            $reasons[] = ['icon' => '💡', 'text' => 'Title dạng opinion/advice → high comment rate'];
        }

        return ['article' => $article, 'reasons' => $reasons];
    }

    private function uniqueSlug(string $base): string
    {
        $slug    = $base ?: 'article';
        $counter = 1;
        while (Article::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$counter}";
            $counter++;
        }
        return $slug;
    }
}
