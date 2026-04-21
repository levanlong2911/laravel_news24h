<?php

namespace App\Http\Controllers;

use App\Jobs\FetchFeedJob;
use App\Services\Admin\ArticleCrawlerService;
use App\Models\Article;
use App\Models\Category;
use App\Models\FeedItem;
use App\Models\FeedSource;
use App\Services\Admin\FeedAggregatorService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class FeedSourceController extends Controller
{
    // ── Feed Sources CRUD ─────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $categoryId = $request->get('category_id');

        $sources = FeedSource::with('category')
            ->when($categoryId, fn($q) => $q->where('category_id', $categoryId))
            ->orderBy('category_id')
            ->orderBy('name')
            ->get();

        $categories = Category::orderBy('name')->get();

        return view('admin.feed-sources.index', [
            'route'      => 'feed-source',
            'action'     => 'feed-source-index',
            'menu'       => 'menu-open',
            'active'     => 'active',
            'sources'    => $sources,
            'categories' => $categories,
            'categoryId' => $categoryId,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'category_id'            => 'required|exists:categories,id',
            'name'                   => 'required|string|max:200',
            'fetch_type'             => 'required|in:rss,crawl,google_news',
            'rss_url'                => 'nullable|url|max:500|required_if:fetch_type,rss',
            'google_news_keyword'    => 'nullable|string|max:200|required_if:fetch_type,google_news',
            'url'                    => 'nullable|url|max:500|required_if:fetch_type,crawl',
            'crawl_selector'         => 'nullable|string|max:200',
            'fetch_interval_minutes' => 'nullable|integer|min:5|max:1440',
        ]);

        // Tự động build Google News RSS URL từ keyword
        $rssUrl = $request->rss_url;
        if ($request->fetch_type === 'google_news' && $request->google_news_keyword) {
            $rssUrl = FetchFeedJob::buildGoogleNewsUrl($request->google_news_keyword);
        }

        FeedSource::create([
            'category_id'            => $request->category_id,
            'name'                   => $request->name,
            'url'                    => $request->url,
            'rss_url'                => $rssUrl,
            'fetch_type'             => $request->fetch_type,
            'crawl_selector'         => $request->crawl_selector,
            'fetch_interval_minutes' => $request->fetch_interval_minutes ?? 60,
            'is_active'              => true,
        ]);

        return back()->with('success', "Đã thêm: {$request->name}");
    }

    public function update(Request $request, FeedSource $feedSource)
    {
        $request->validate([
            'name'                   => 'required|string|max:200',
            'fetch_type'             => 'required|in:rss,crawl,google_news',
            'rss_url'                => 'nullable|url|max:500',
            'url'                    => 'nullable|url|max:500',
            'crawl_selector'         => 'nullable|string|max:200',
            'fetch_interval_minutes' => 'nullable|integer|min:5|max:1440',
        ]);

        $feedSource->update($request->only([
            'name', 'url', 'rss_url', 'fetch_type',
            'crawl_selector', 'fetch_interval_minutes',
        ]));

        return back()->with('success', 'Đã cập nhật.');
    }

    public function toggleActive(FeedSource $feedSource)
    {
        $feedSource->update(['is_active' => !$feedSource->is_active]);
        return back()->with('success', $feedSource->is_active ? 'Đã bật.' : 'Đã tắt.');
    }

    public function destroy(FeedSource $feedSource)
    {
        $feedSource->delete();
        return back()->with('success', 'Đã xóa.');
    }

    // ── Fetch Actions ─────────────────────────────────────────────────────────

    // Fetch 1 source ngay lập tức
    public function fetchOne(FeedSource $feedSource, FeedAggregatorService $aggregator)
    {
        $aggregator->dispatchOne($feedSource);
        return back()->with('success', "Đang fetch: {$feedSource->name}");
    }

    // Fetch tất cả sources của 1 category
    public function fetchByCategory(Request $request, FeedAggregatorService $aggregator)
    {
        $categoryId = $request->get('category_id');
        $count      = $aggregator->dispatchByCategory($categoryId);
        return back()->with('success', "Đã dispatch {$count} sources.");
    }

    // Fetch tất cả sources đến hạn
    public function fetchDue(FeedAggregatorService $aggregator)
    {
        $count = $aggregator->dispatchDue();
        return back()->with('success', "Đã dispatch {$count} sources đến hạn.");
    }

    // ── Feed Items ────────────────────────────────────────────────────────────

    public function items(Request $request)
    {
        $categoryId   = $request->get('category_id');
        $feedSourceId = $request->get('feed_source_id');
        $status       = $request->get('status', 'all');

        $items = FeedItem::with(['feedSource', 'category', 'article'])
            ->when($categoryId,   fn($q) => $q->where('category_id', $categoryId))
            ->when($feedSourceId, fn($q) => $q->where('feed_source_id', $feedSourceId))
            ->when($status !== 'all', fn($q) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->paginate(30);

        $categories = Category::orderBy('name')->get();
        $sources    = FeedSource::orderBy('name')->get();

        return view('admin.feed-sources.items', [
            'route'        => 'feed-source',
            'action'       => 'feed-item-index',
            'menu'         => 'menu-open',
            'active'       => 'active',
            'items'        => $items,
            'categories'   => $categories,
            'sources'      => $sources,
            'categoryId'   => $categoryId,
            'feedSourceId' => $feedSourceId,
            'status'       => $status,
        ]);
    }

    // Crawl nội dung cho các feed items đã chọn
    public function crawlContent(Request $request, ArticleCrawlerService $crawler)
    {
        $ids = array_filter((array) $request->get('selected_ids', []));
        if (empty($ids)) {
            return back()->with('error', 'Chưa chọn bài nào.');
        }

        if (count($ids) > 20) {
            return back()->with('error', 'Tối đa 20 bài mỗi lần crawl.');
        }

        $items = FeedItem::whereIn('id', $ids)
            ->whereNull('raw_content')
            ->get();

        if ($items->isEmpty()) {
            return back()->with('error', 'Tất cả bài đã có nội dung rồi.');
        }

        $urls       = $items->pluck('url', 'id')->all();
        $contentMap = $crawler->crawlMany(array_values($urls));

        $done = 0;
        foreach ($items as $item) {
            $content = $contentMap[$item->url] ?? '';
            if (strlen($content) < 100) continue;

            $item->update([
                'raw_content' => $content,
                'status'      => 'done',
            ]);
            $done++;
        }

        $skipped = count($items) - $done;
        $msg = "Đã crawl {$done}/{$items->count()} bài.";
        if ($skipped > 0) {
            $msg .= " {$skipped} bài không lấy được nội dung (bị chặn hoặc trống).";
        }

        return back()->with('success', $msg);
    }

    // Push feed_item → articles table để AI pipeline xử lý
    public function pushToArticles(Request $request)
    {
        $ids = array_filter((array) $request->get('selected_ids', []));
        if (empty($ids)) {
            return back()->with('error', 'Chưa chọn bài nào.');
        }

        $feedItems = FeedItem::with('feedSource')
            ->whereIn('id', $ids)
            ->where('status', '!=', 'done')
            ->get();

        // Batch check duplicates trước loop, tránh N+1
        $existingHashes = Article::whereIn('source_url_hash', $feedItems->pluck('url_hash'))
            ->pluck('source_url_hash')
            ->flip()
            ->all();

        $done   = 0;
        $errors = [];

        foreach ($feedItems as $item) {
            if (isset($existingHashes[$item->url_hash])) {
                $errors[] = "'{$item->title}': đã tồn tại trong articles.";
                continue;
            }

            $slug = $this->uniqueSlug(Str::slug($item->title ?: 'article'));

            $article = Article::create([
                'category_id'     => $item->category_id,
                'source_url'      => $item->url,
                'source_url_hash' => $item->url_hash,
                'source_title'    => $item->title,
                'source_name'     => parse_url($item->url, PHP_URL_HOST) ?? '',
                'thumbnail'       => $item->thumbnail,
                'title'           => $item->title,
                'slug'            => $slug,
                'content'         => $item->raw_content,
                'viral_score'     => 0,
                'status'          => 'pending',
                'expires_at'      => now()->addHours(48),
                'crawled_by'      => auth()->id(),
            ]);

            $item->update(['status' => 'done', 'article_id' => $article->id]);
            $done++;
        }

        $msg = "Đã push {$done}/" . count($feedItems) . " bài vào Articles.";
        if ($errors) {
            return back()->with('error', $msg . ' Lỗi: ' . implode('; ', $errors));
        }

        return back()->with('success', $msg);
    }

    public function destroyItem(FeedItem $feedItem)
    {
        $feedItem->delete();
        return back()->with('success', 'Đã xóa.');
    }

    public function destroyItemsAll(Request $request)
    {
        $categoryId   = $request->get('category_id');
        $feedSourceId = $request->get('feed_source_id');

        $count = FeedItem::when($categoryId,   fn($q) => $q->where('category_id', $categoryId))
            ->when($feedSourceId, fn($q) => $q->where('feed_source_id', $feedSourceId))
            ->where('status', 'pending')
            ->delete();

        return back()->with('success', "Đã xóa {$count} feed items pending.");
    }

    // ── Helper ────────────────────────────────────────────────────────────────

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
