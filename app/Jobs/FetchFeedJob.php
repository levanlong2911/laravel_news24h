<?php

namespace App\Jobs;

use App\Models\Article;
use App\Models\FeedItem;
use App\Models\FeedSource;
use App\Services\Admin\ArticleCrawlerService;
use GuzzleHttp\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FetchFeedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 60;

    public function __construct(public FeedSource $source) {}

    public function handle(ArticleCrawlerService $crawler): void
    {
        Log::info("[FetchFeed] Start: {$this->source->name}", ['type' => $this->source->fetch_type]);

        $items = match ($this->source->fetch_type) {
            'rss'         => $this->fetchRss(),
            'google_news' => $this->fetchGoogleNews(),
            'crawl'       => $this->fetchCrawl($crawler),
        };

        // Batch dedup: load all existing hashes trước loop, tránh N+1
        $incomingHashes = array_map(
            fn($i) => FeedItem::urlHash($i['url'] ?? ''),
            array_filter($items, fn($i) => !empty($i['url']))
        );
        $existingHashes = FeedItem::whereIn('url_hash', $incomingHashes)
            ->pluck('url_hash')
            ->flip()
            ->all();

        $saved = 0;
        foreach ($items as $item) {
            if (empty($item['url']) || empty($item['title'])) continue;
            $hash = FeedItem::urlHash($item['url']);
            if (isset($existingHashes[$hash])) continue;

            FeedItem::create([
                'feed_source_id' => $this->source->id,
                'category_id'    => $this->source->category_id,
                'title'          => $item['title'],
                'url'            => $item['url'],
                'url_hash'       => $hash,
                'thumbnail'      => $item['thumbnail'] ?? null,
                'published_at'   => $item['published_at'] ?? null,
                'raw_content'    => $item['content'] ?? null,
                'status'         => empty($item['content']) ? 'pending' : 'done',
            ]);

            $saved++;
        }

        $this->source->update([
            'total_fetched'   => $this->source->total_fetched + $saved,
            'last_fetched_at' => now(),
        ]);

        Log::info("[FetchFeed] Done: {$this->source->name}", ['saved' => $saved, 'total' => count($items)]);
    }

    // ── Google News RSS ───────────────────────────────────────────────────────

    private function fetchGoogleNews(): array
    {
        $items = $this->fetchRssFromUrl($this->source->rss_url);

        return array_map(function ($item) {
            $item['title'] = $this->stripGoogleNewsSource($item['title']);
            return $item;
        }, $items);
    }

    private function stripGoogleNewsSource(string $title): string
    {
        if (preg_match('/^(.+)\s-\s([^-]{3,60})$/', $title, $m)) {
            return trim($m[1]);
        }
        return $title;
    }

    // Build Google News RSS URL từ keyword
    public static function buildGoogleNewsUrl(string $keyword, string $lang = 'en-US', string $country = 'US'): string
    {
        $ceid = $country . ':' . explode('-', $lang)[0];
        return 'https://news.google.com/rss/search?' . http_build_query([
            'q'    => $keyword,
            'hl'   => $lang,
            'gl'   => $country,
            'ceid' => $ceid,
        ]);
    }

    // ── RSS Parser ────────────────────────────────────────────────────────────

    private function fetchRss(): array
    {
        return $this->fetchRssFromUrl($this->source->rss_url);
    }

    private function makeClient(array $extraHeaders = []): Client
    {
        return new Client([
            'timeout' => 20,
            'headers' => array_merge([
                'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0 Safari/537.36',
                'Accept-Language' => 'en-US,en;q=0.9',
                'Accept-Encoding' => 'gzip, deflate',
            ], $extraHeaders),
            'verify'          => false,
            'allow_redirects' => ['max' => 5],
        ]);
    }

    private function fetchRssFromUrl(string $url): array
    {
        $client = $this->makeClient([
            'Accept' => 'application/rss+xml, application/xml, text/xml, */*',
        ]);

        try {
            $xml = (string) $client->get($url)->getBody();
        } catch (\Throwable $e) {
            Log::warning("[FetchFeed] RSS fetch failed: {$url} — {$e->getMessage()}");
            return [];
        }

        // Fix encoding trước khi parse
        $xml = mb_convert_encoding($xml, 'UTF-8', mb_detect_encoding($xml) ?: 'UTF-8');

        libxml_use_internal_errors(true);
        $feed = simplexml_load_string($xml);
        libxml_clear_errors();

        if (!$feed) {
            Log::warning("[FetchFeed] RSS parse failed: {$url}");
            return [];
        }

        // Hỗ trợ cả RSS 2.0 và Atom
        $items = [];

        if (isset($feed->channel->item)) {
            // RSS 2.0
            foreach ($feed->channel->item as $item) {
                $items[] = $this->parseRssItem($item);
            }
        } elseif (isset($feed->entry)) {
            // Atom
            foreach ($feed->entry as $entry) {
                $items[] = $this->parseAtomEntry($entry);
            }
        }

        return array_filter($items, fn($i) => !empty($i['url']));
    }

    private function parseRssItem(\SimpleXMLElement $item): array
    {
        $url = (string) ($item->link ?? '');

        // Một số feed dùng <guid> thay <link>
        if (empty($url) && isset($item->guid)) {
            $guid = (string) $item->guid;
            if (filter_var($guid, FILTER_VALIDATE_URL)) {
                $url = $guid;
            }
        }

        // Thumbnail từ media:content hoặc enclosure
        $thumbnail = null;
        $namespaces = $item->getNamespaces(true);
        if (isset($namespaces['media'])) {
            $media = $item->children($namespaces['media']);
            $thumbnail = (string) ($media->content['url'] ?? '');
        }
        if (empty($thumbnail) && isset($item->enclosure)) {
            $type = (string) $item->enclosure['type'];
            if (str_starts_with($type, 'image/')) {
                $thumbnail = (string) $item->enclosure['url'];
            }
        }

        // Content từ content:encoded nếu có
        $content = null;
        if (isset($namespaces['content'])) {
            $contentNs = $item->children($namespaces['content']);
            $content   = (string) ($contentNs->encoded ?? '');
        }

        return [
            'title'        => html_entity_decode(strip_tags((string) $item->title), ENT_QUOTES, 'UTF-8'),
            'url'          => trim($url),
            'thumbnail'    => $thumbnail ?: null,
            'published_at' => $this->parseDate((string) ($item->pubDate ?? '')),
            'content'      => $content ?: null,
        ];
    }

    private function parseAtomEntry(\SimpleXMLElement $entry): array
    {
        $url = '';
        foreach ($entry->link as $link) {
            if ((string) $link['rel'] === 'alternate' || empty((string) $link['rel'])) {
                $url = (string) $link['href'];
                break;
            }
        }

        return [
            'title'        => html_entity_decode(strip_tags((string) $entry->title), ENT_QUOTES, 'UTF-8'),
            'url'          => trim($url),
            'thumbnail'    => null,
            'published_at' => $this->parseDate((string) ($entry->published ?? $entry->updated ?? '')),
            'content'      => null,
        ];
    }

    private function parseDate(string $dateStr): ?string
    {
        if (empty($dateStr)) return null;
        $ts = strtotime($dateStr);
        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    }

    // ── Crawl Parser ─────────────────────────────────────────────────────────

    private function fetchCrawl(ArticleCrawlerService $crawler): array
    {
        $client = $this->makeClient(['Accept' => 'text/html,application/xhtml+xml,*/*']);

        try {
            $html = (string) $client->get($this->source->url)->getBody();
        } catch (\Throwable $e) {
            Log::warning("[FetchFeed] Crawl failed: {$this->source->url} — {$e->getMessage()}");
            return [];
        }

        $links = $this->extractLinks($html, $this->source->url, $this->source->crawl_selector);

        // Lấy nội dung từng bài (dùng Jina/Readability qua ArticleCrawlerService)
        $urls       = array_column($links, 'url');
        $contentMap = [];
        if (!empty($urls)) {
            try {
                $contentMap = $crawler->crawlMany(array_slice($urls, 0, 10));
            } catch (\Throwable $e) {
                Log::warning("[FetchFeed] Content crawl error: {$e->getMessage()}");
            }
        }

        return array_map(function ($link) use ($contentMap) {
            $link['content']      = $contentMap[$link['url']] ?? null;
            $link['published_at'] = null;
            return $link;
        }, $links);
    }

    private function extractLinks(string $html, string $baseUrl, ?string $selector): array
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        libxml_clear_errors();

        $xpath  = new \DOMXPath($dom);
        $query  = $selector ? $this->selectorToXpath($selector) : '//article//a | //h2//a | //h3//a';
        $nodes  = $xpath->query($query);

        $links = [];
        $seen  = [];

        foreach ($nodes as $node) {
            $href  = $node->getAttribute('href');
            $title = trim($node->textContent);

            if (strlen($title) < 20) continue;

            $url = $this->toAbsoluteUrl($href, $baseUrl);
            if (!$url || isset($seen[$url])) continue;
            if (!filter_var($url, FILTER_VALIDATE_URL)) continue;

            // Bỏ link về trang chủ hoặc category
            $path = parse_url($url, PHP_URL_PATH) ?? '/';
            if (strlen($path) < 10) continue;

            $seen[$url]  = true;
            $links[] = ['title' => $title, 'url' => $url];

            if (count($links) >= 30) break;
        }

        return $links;
    }

    private function selectorToXpath(string $selector): string
    {
        // Chuyển CSS-like selector đơn giản sang XPath
        // "article a" → "//article//a"
        // ".post-title a" → "//*[contains(@class,'post-title')]//a"
        $parts = preg_split('/\s+/', trim($selector));
        $xpath = [];

        foreach ($parts as $part) {
            if (str_starts_with($part, '.')) {
                $class  = substr($part, 1);
                $xpath[] = "*[contains(@class,'{$class}')]";
            } elseif (str_starts_with($part, '#')) {
                $id     = substr($part, 1);
                $xpath[] = "*[@id='{$id}']";
            } else {
                $xpath[] = $part;
            }
        }

        return '//' . implode('//', $xpath);
    }

    private function toAbsoluteUrl(string $href, string $baseUrl): ?string
    {
        $href = trim($href);
        if (empty($href) || str_starts_with($href, '#') || str_starts_with($href, 'javascript:')) {
            return null;
        }
        if (str_starts_with($href, 'http')) return $href;

        $parsed = parse_url($baseUrl);
        if (empty($parsed['host'])) return null;

        $base = $parsed['scheme'] . '://' . $parsed['host'];
        return $base . (str_starts_with($href, '/') ? $href : '/' . $href);
    }

    public function failed(\Throwable $e): void
    {
        Log::error("[FetchFeed] Job failed: {$this->source->name} — {$e->getMessage()}");
    }
}
