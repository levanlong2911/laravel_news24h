<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessKeywordJob;
use App\Jobs\WriteArticleJob;
use App\Models\Article;
use App\Models\Keyword;
use App\Models\RawArticle;
use App\Services\Admin\ArticleCrawlerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class RawArticleController extends Controller
{
    // Danh sách raw articles — group theo keyword, mỗi keyword top 10
    public function index(Request $request)
    {
        $keywordId = $request->get('keyword_id');
        $status    = $request->get('status', 'all');

        $keywords = Keyword::where('is_active', true)->orderBy('sort_order')->get();
        $kwIds    = $keywordId ? [$keywordId] : $keywords->pluck('id')->all();

        // Load tất cả raw articles 1 lần — tránh N+1
        $allArticles = RawArticle::with('article')
            ->whereIn('keyword_id', $kwIds)
            ->when($status !== 'all', fn($q) => $q->where('status', $status))
            ->orderByDesc('viral_score')
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('keyword_id');

        $kwList  = $keywordId ? $keywords->where('id', $keywordId) : $keywords;
        $grouped = $kwList->map(function ($kw) use ($allArticles) {
            $articles = $allArticles->get($kw->id, collect())->take(10);

            return [
                'keyword'  => $kw,
                'articles' => $articles,
                'stats'    => [
                    'total'      => $articles->count(),
                    'pending'    => $articles->where('status', 'pending')->count(),
                    'generating' => $articles->where('status', 'generating')->count(),
                    'done'       => $articles->where('status', 'done')->count(),
                    'failed'     => $articles->where('status', 'failed')->count(),
                ],
            ];
        })->filter(fn($g) => $g['articles']->isNotEmpty())->values();

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

    // Fetch tất cả active keywords → xóa data cũ + cache → fetch mới
    public function fetchAll()
    {
        $keywords = Keyword::where('is_active', true)->get();

        // Xóa tất cả raw articles cũ (trừ bài đang generating)
        RawArticle::where('status', '!=', 'generating')->delete();

        // Xóa SerpAPI cache — dùng đúng query mà job sẽ gọi
        foreach ($keywords as $kw) {
            $query = !empty($kw->search_keyword) ? $kw->search_keyword : ($kw->name . ' news');
            Cache::forget('serp_news_v2_' . md5($query));
        }

        // Fetch từng keyword (sync = chạy ngay)
        foreach ($keywords as $keyword) {
            ProcessKeywordJob::dispatch($keyword)->onQueue('articles');
        }

        return back()->with('success', "Đã xóa data cũ và fetch {$keywords->count()} keywords mới.");
    }

    // Generate nhiều raw articles cùng lúc (multi-select)
    public function generateSelected(Request $request)
    {
        $ids = array_filter((array) $request->get('selected_ids', []));
        if (empty($ids)) {
            return back()->with('error', 'Chưa chọn bài viết nào.');
        }

        // Rate limit: tối đa 20 bài mỗi lần, tránh spam queue
        if (count($ids) > 20) {
            return back()->with('error', 'Chỉ được chọn tối đa 20 bài mỗi lần.');
        }

        $key = 'generate-selected';
        if (RateLimiter::tooManyAttempts($key, 3)) {
            $seconds = RateLimiter::availableIn($key);
            return back()->with('error', "Quá nhiều request. Vui lòng đợi {$seconds}s.");
        }
        RateLimiter::hit($key, 60);

        $articles = RawArticle::whereIn('id', $ids)
            ->whereIn('status', ['pending', 'failed'])
            ->get();

        if ($articles->isEmpty()) {
            return back()->with('error', 'Không có bài pending/failed trong danh sách đã chọn.');
        }

        foreach ($articles as $raw) {
            WriteArticleJob::dispatch($raw)->onQueue('articles');
        }

        return back()->with('success', "Đang generate {$articles->count()} bài với Claude AI.");
    }

    // Fetch 1 keyword cụ thể
    public function fetchOne(Request $request)
    {
        $keyword = Keyword::find($request->get('keyword_id'));

        if (!$keyword) {
            return back()->with('error', 'Keyword not found');
        }

        // Rate limit: mỗi keyword 1 lần/30 giây
        $key = 'fetch-keyword-' . $keyword->id;
        if (RateLimiter::tooManyAttempts($key, 1)) {
            $seconds = RateLimiter::availableIn($key);
            return back()->with('error', "Please wait {$seconds}s before fetching {$keyword->name} again.");
        }
        RateLimiter::hit($key, 30);

        // Xóa SerpAPI cache để job lấy data mới nhất (không dùng cached results cũ)
        $query = !empty($keyword->search_keyword) ? $keyword->search_keyword : ($keyword->name . ' news');
        Cache::forget('serp_news_v2_' . md5($query));

        ProcessKeywordJob::dispatch($keyword)->onQueue('articles');

        return back()->with('success', "Fetching: {$keyword->name}. Check back in ~1 minute.");
    }

    // Crawl URL → lưu vào articles (không AI). Cho phép tải lại để tạo bài mới.
    public function save(Request $request, RawArticle $rawArticle, ArticleCrawlerService $crawler)
    {
        $urlHash = md5($rawArticle->url);

        // Xóa crawl cache để lấy nội dung mới nhất
        Cache::forget('crawl:' . $urlHash);

        // Crawl nội dung
        $contents = $crawler->crawlMany([$rawArticle->url]);
        dd($contents);
        $content  = trim($contents[$rawArticle->url] ?? '');

        if (strlen($content) < 100) {
            return back()->with('error', 'Không crawl được nội dung bài viết.');
        }

        $title   = $rawArticle->title;
        $slug    = $this->uniqueSlug(Str::slug($title ?: 'article'));

        // Nếu URL hash đã tồn tại trong articles (dù article_id có hay không)
        // → thêm timestamp để tránh duplicate unique constraint
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
        ]);

        $rawArticle->update(['status' => 'done', 'article_id' => $article->id]);

        return back()->with('success', 'Đã lưu bài viết: ' . $title);
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

    // Trigger AI pipeline cho 1 raw article
    public function generate(RawArticle $rawArticle)
    {
        if ($rawArticle->status === 'generating') {
            return back()->with('error', 'Already generating...');
        }

        if ($rawArticle->status === 'done') {
            return back()->with('error', 'Already generated. View the article.');
        }

        // Rate limit: tối đa 5 generate mỗi phút (tránh spam Claude API)
        $key = 'generate-article';
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            return back()->with('error', "Too many requests. Please wait {$seconds}s.");
        }
        RateLimiter::hit($key, 60);

        WriteArticleJob::dispatch($rawArticle)->onQueue('articles');

        return back()->with('success', "Generating: {$rawArticle->title}");
    }

    // Retry failed article
    public function retry(RawArticle $rawArticle)
    {
        // Rate limit: tối đa 5 retry mỗi phút
        $key = 'generate-article';
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            return back()->with('error', "Too many requests. Please wait {$seconds}s.");
        }
        RateLimiter::hit($key, 60);

        $rawArticle->update(['status' => 'pending']);
        WriteArticleJob::dispatch($rawArticle)->onQueue('articles');

        return back()->with('success', "Retrying: {$rawArticle->title}");
    }

    // Generate tất cả pending articles của 1 keyword
    public function generateKeyword(Request $request)
    {
        $keywordId = $request->get('keyword_id');

        $pending = RawArticle::where('keyword_id', $keywordId)
            ->whereIn('status', ['pending', 'failed'])
            ->get();

        if ($pending->isEmpty()) {
            return back()->with('error', 'No pending/failed articles for this keyword.');
        }

        // Rate limit: 1 lần generate-all mỗi 3 phút/keyword
        $key = 'generate-keyword-' . $keywordId;
        if (RateLimiter::tooManyAttempts($key, 1)) {
            $seconds = RateLimiter::availableIn($key);
            return back()->with('error', "Please wait {$seconds}s before generating this keyword again.");
        }
        RateLimiter::hit($key, 180); // 3 phút

        foreach ($pending as $raw) {
            WriteArticleJob::dispatch($raw)->onQueue('articles');
        }

        $kwName = $pending->first()->keyword->name ?? '';
        return back()->with('success', "Generating {$pending->count()} articles for {$kwName}.");
    }

    // Xóa tất cả bài của 1 keyword (kể cả done) → fetch lại để có timestamp chính xác
    public function clearRefetch(Request $request)
    {
        $keyword = Keyword::find($request->get('keyword_id'));

        if (!$keyword) {
            return back()->with('error', 'Keyword not found');
        }

        $deleted = RawArticle::where('keyword_id', $keyword->id)
            ->where('status', '!=', 'generating')
            ->delete();

        // Xóa đúng cache key mà job sẽ dùng
        $query = !empty($keyword->search_keyword) ? $keyword->search_keyword : ($keyword->name . ' news');
        Cache::forget('serp_news_v2_' . md5($query));

        ProcessKeywordJob::dispatch($keyword)->onQueue('articles');

        return back()->with('success', "Cleared {$deleted} articles + cache for {$keyword->name}. Fetching fresh data...");
    }

    public function destroy(RawArticle $rawArticle)
    {
        $rawArticle->delete();
        return back()->with('success', 'Deleted.');
    }
}
