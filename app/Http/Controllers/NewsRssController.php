<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\Category;
use App\Models\NewsWeb;
use App\Models\RssItem;
use App\Services\Admin\ArticleCrawlerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class NewsRssController extends Controller
{
    private string $pythonUrl;
    private string $pythonKey;

    public function __construct()
    {
        $this->pythonUrl = config('services.crawler.url', '');
        $this->pythonKey = config('services.crawler.key', '');
    }

    public function index(Request $request)
    {
        $categoryId = $request->get('category_id');
        $newsWebId  = $request->get('news_web_id');
        $status     = $request->get('status', 'all');

        $newsWebs = NewsWeb::with('category')
            ->where('is_active', true)
            ->whereNotNull('rss_url')
            ->where('rss_url', '!=', '')
            ->when($categoryId, fn($q) => $q->where('category_id', $categoryId))
            ->when($newsWebId, fn($q) => $q->where('id', $newsWebId))
            ->get();

        $webIds = $newsWebs->pluck('id');

        $allItems = RssItem::with(['newsWeb.category', 'article'])
            ->whereIn('news_web_id', $webIds)
            ->when($status !== 'all', fn($q) => $q->where('status', $status))
            ->orderByDesc('published_at')
            ->get();

        // Group by category — mỗi card là 1 category, bài sắp theo mới nhất
        $grouped = $newsWebs
            ->groupBy('category_id')
            ->map(function ($websInCat) use ($allItems, $newsWebId) {
                $catWebIds = $websInCat->pluck('id');
                $items = $allItems->whereIn('news_web_id', $catWebIds);

                if ($newsWebId) {
                    $items = $items->where('news_web_id', $newsWebId);
                }

                if ($items->isEmpty()) return null;

                return [
                    'category' => $websInCat->first()->category,
                    'items'    => $items->values()->take(50),
                    'stats'    => [
                        'pending' => $items->where('status', 'pending')->count(),
                        'done'    => $items->where('status', 'done')->count(),
                    ],
                ];
            })
            ->filter()
            ->values();

        $allStats = RssItem::selectRaw('status, count(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        $categories  = Category::orderBy('name')->get();
        $allNewsWebs = NewsWeb::where('is_active', true)
            ->whereNotNull('rss_url')
            ->where('rss_url', '!=', '')
            ->with('category')
            ->get();

        return view('news-rss.index', [
            'route'       => 'news-rss',
            'action'      => 'news-rss-index',
            'menu'        => 'menu-open',
            'active'      => 'active',
            'grouped'     => $grouped,
            'allStats'    => $allStats,
            'categories'  => $categories,
            'allNewsWebs' => $allNewsWebs,
            'categoryId'  => $categoryId,
            'newsWebId'   => $newsWebId,
            'status'      => $status,
        ]);
    }

    public function fetchAll()
    {
        $newsWebs = NewsWeb::where('is_active', true)
            ->whereNotNull('rss_url')
            ->where('rss_url', '!=', '')
            ->whereIn('feed_type', ['rss', 'sitemap'])
            ->get();

        if ($newsWebs->isEmpty()) {
            return back()->with('error', 'Không có nguồn nào. Hãy chạy Auto Detect trước.');
        }

        $data = $this->callPythonRss($newsWebs);

        if ($data === null) {
            return back()->with('error', 'Không kết nối được Python service. Kiểm tra uvicorn đang chạy.');
        }

        $saved = $this->storeRssItems($data['articles'], $newsWebs);
        $s     = $data['stats'];

        return back()->with('success',
            "Fetch {$s['feeds_ok']}/{$newsWebs->count()} sources — {$s['total_entries']} bài trong 15h — {$saved} bài mới."
        );
    }

    public function fetchOne(Request $request)
    {
        $newsWeb = NewsWeb::find($request->get('news_web_id'));

        if (!$newsWeb || empty($newsWeb->rss_url) || $newsWeb->feed_type === 'none') {
            return back()->with('error', 'Nguồn chưa được detect. Hãy chạy Auto Detect trước.');
        }

        $key = 'fetch-rss-' . $newsWeb->id;
        if (RateLimiter::tooManyAttempts($key, 1)) {
            $seconds = RateLimiter::availableIn($key);
            return back()->with('error', "Vui lòng chờ {$seconds}s trước khi fetch lại {$newsWeb->domain}.");
        }
        RateLimiter::hit($key, 30);

        $data = $this->callPythonRss(collect([$newsWeb]));

        if ($data === null) {
            return back()->with('error', 'Không kết nối được Python service.');
        }

        $saved = $this->storeRssItems($data['articles'], collect([$newsWeb]));
        $s     = $data['stats'];

        $type = strtoupper($newsWeb->feed_type);
        return back()->with('success',
            "[{$type}] {$newsWeb->domain}: {$s['total_entries']} bài trong 15h — {$saved} bài mới."
        );
    }

    public function save(Request $request, RssItem $rssItem, ArticleCrawlerService $crawler)
    {
        $urlHash = md5($rssItem->url);
        Cache::forget('crawl:' . $urlHash);

        $contents = $crawler->crawlMany([$rssItem->url]);
        $content  = trim($contents[$rssItem->url] ?? '');

        if (strlen($content) < 100) {
            return $request->expectsJson()
                ? response()->json(['success' => false, 'message' => 'Không crawl được nội dung bài viết.'])
                : back()->with('error', 'Không crawl được nội dung bài viết.');
        }

        $already   = Article::where('source_url_hash', $urlHash)->exists();
        $finalHash = $already ? md5($rssItem->url . '_' . time()) : $urlHash;
        $newsWeb   = $rssItem->newsWeb;

        $article = Article::retryCreate([
            'category_id'     => $newsWeb->category_id ?? null,
            'source_url'      => $rssItem->url,
            'source_url_hash' => $finalHash,
            'source_title'    => $rssItem->title,
            'source_name'     => $newsWeb->domain,
            'thumbnail'       => $rssItem->image,
            'title'           => $rssItem->title,
            'content'         => $content,
            'status'          => 'pending',
            'expires_at'      => now()->addHours(48),
            'crawled_by'      => auth()->id(),
        ], Str::slug($rssItem->title ?: 'article'));

        $rssItem->update(['status' => 'done', 'article_id' => $article->id]);

        return $request->expectsJson()
            ? response()->json([
                'success'     => true,
                'message'     => 'Đã lưu: ' . $rssItem->title,
                'article_url' => route('article.show', $article),
            ])
            : back()->with('success', 'Đã lưu: ' . $rssItem->title);
    }

    public function destroy(RssItem $rssItem)
    {
        $rssItem->delete();
        return back()->with('success', 'Đã xóa.');
    }

    public function clearAndRefreshCategory(Request $request)
    {
        $categoryId = $request->get('category_id');

        if (!$categoryId) {
            return back()->with('error', 'Vui lòng chọn category.');
        }

        $newsWebs = NewsWeb::where('is_active', true)
            ->where('category_id', $categoryId)
            ->whereNotNull('rss_url')
            ->where('rss_url', '!=', '')
            ->whereIn('feed_type', ['rss', 'sitemap'])
            ->get();

        if ($newsWebs->isEmpty()) {
            return back()->with('error', 'Category này chưa có nguồn RSS nào.');
        }

        // 1. Xóa toàn bộ rss_items của category
        $deleted = RssItem::whereIn('news_web_id', $newsWebs->pluck('id'))->delete();

        // 2. Clear dedup cache Python
        try {
            Http::timeout(10)
                ->withHeaders(['X-API-Key' => $this->pythonKey])
                ->delete($this->pythonUrl . '/cache');
        } catch (\Exception $e) {
            Log::warning('[rss/clear] cache clear failed: ' . $e->getMessage());
        }

        // 3. Fetch lại toàn bộ
        $data = $this->callPythonRss($newsWebs);

        if ($data === null) {
            return back()->with('error', "Đã xóa {$deleted} items nhưng không kết nối được Python service.");
        }

        $saved = $this->storeRssItems($data['articles'], $newsWebs);
        $s     = $data['stats'];

        return back()->with('success',
            "Cleared {$deleted} items — {$newsWebs->count()} sources — {$s['total_entries']} bài → {$saved} bài mới."
        );
    }

    public function fetchByCategory(Request $request)
    {
        $categoryId = $request->get('category_id');

        if (!$categoryId) {
            return back()->with('error', 'Vui lòng chọn category.');
        }

        $newsWebs = NewsWeb::where('is_active', true)
            ->where('category_id', $categoryId)
            ->whereNotNull('rss_url')
            ->where('rss_url', '!=', '')
            ->whereIn('feed_type', ['rss', 'sitemap'])
            ->get();

        if ($newsWebs->isEmpty()) {
            return back()->with('error', 'Category này chưa có nguồn RSS nào. Hãy chạy Auto Detect trước.');
        }

        $data = $this->callPythonRss($newsWebs);

        if ($data === null) {
            return back()->with('error', 'Không kết nối được Python service.');
        }

        $saved = $this->storeRssItems($data['articles'], $newsWebs);
        $s     = $data['stats'];

        return back()->with('success',
            "Category fetch: {$s['feeds_ok']}/{$newsWebs->count()} sources — {$s['total_entries']} bài trong 15h — {$saved} bài mới."
        );
    }

    public function autoDetect()
    {
        $sources = NewsWeb::whereNull('rss_url')
            ->orWhere('rss_url', '')
            ->get(['id', 'domain', 'base_url']);

        if ($sources->isEmpty()) {
            return back()->with('success', 'Tất cả nguồn đã có RSS URL rồi.');
        }

        if (!$this->pythonUrl) {
            return back()->with('error', 'CRAWLER_SERVICE_URL chưa được cấu hình.');
        }

        try {
            $payload = $sources->map(fn($s) => [
                'id'       => $s->id,
                'domain'   => $s->domain,
                'base_url' => $s->base_url ?? '',
            ])->values()->all();

            $response = Http::timeout(240)
                ->withHeaders(['X-API-Key' => $this->pythonKey])
                ->post($this->pythonUrl . '/rss/detect', ['sources' => $payload]);

            if (!$response->successful()) {
                return back()->with('error', 'Python service lỗi: HTTP ' . $response->status());
            }

            $results     = $response->json('results', []);
            $foundRss     = 0;
            $foundSitemap = 0;

            foreach ($results as $id => $info) {
                if (!$info) continue;

                NewsWeb::where('id', $id)->update([
                    'rss_url'   => $info['url'],
                    'feed_type' => $info['type'],  // 'rss' | 'sitemap'
                ]);

                $info['type'] === 'rss' ? $foundRss++ : $foundSitemap++;
            }

            $total   = $sources->count();
            $notFound = $total - $foundRss - $foundSitemap;

            return back()->with('success',
                "Auto-detect xong {$total} nguồn: RSS={$foundRss} | Sitemap={$foundSitemap} | Không tìm được={$notFound}"
            );
        } catch (\Exception $e) {
            Log::error('[rss/detect] ' . $e->getMessage());
            return back()->with('error', 'Lỗi kết nối Python service: ' . $e->getMessage());
        }
    }

    private function callPythonRss(\Illuminate\Support\Collection $newsWebs, int $hours = 15): ?array
    {
        if (!$this->pythonUrl) {
            Log::warning('[rss] CRAWLER_SERVICE_URL chưa được cấu hình.');
            return null;
        }

        $feeds = $newsWebs->map(fn($w) => [
            'id'       => $w->id,
            'url'      => $w->rss_url,
            'type'     => $w->feed_type,  // 'rss' | 'sitemap'
            'base_url' => $w->base_url ?? '',
        ])->values()->all();

        try {
            $response = Http::timeout(90)
                ->withHeaders(['X-API-Key' => $this->pythonKey])
                ->post($this->pythonUrl . '/rss/fetch', [
                    'feeds'           => $feeds,
                    'hours'           => $hours,
                    'limit'           => 300,
                    'include_no_date' => false,
                ]);

            if (!$response->successful()) {
                Log::error('[rss] Python service HTTP ' . $response->status());
                return null;
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::error('[rss] Python service exception: ' . $e->getMessage());
            return null;
        }
    }

    private function storeRssItems(array $articles, \Illuminate\Support\Collection $newsWebs): int
    {
        // source = news_web.id (UUID) — map trực tiếp theo id
        $webMap    = $newsWebs->keyBy('id');
        $expiresAt = now()->addHours(24);
        $saved     = 0;

        foreach ($articles as $art) {
            $newsWeb = $webMap->get($art['source']);
            if (!$newsWeb) continue;

            $item = RssItem::firstOrCreate(
                ['url_hash' => $art['url_hash']],
                [
                    'news_web_id'  => $newsWeb->id,
                    'title'        => $art['title'],
                    'url'          => $art['url'],
                    'image'        => $art['image'] ?? null,
                    'description'  => $art['description'] ?? null,
                    'published_at' => $art['published_at'],
                    'status'       => 'pending',
                    'expires_at'   => $expiresAt,
                ]
            );

            if (!$item->wasRecentlyCreated) {
                $item->update(['expires_at' => $expiresAt]);
            } else {
                $saved++;
            }
        }

        return $saved;
    }
}
