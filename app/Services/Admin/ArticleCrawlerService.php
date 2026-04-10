<?php

namespace App\Services\Admin;

use fivefilters\Readability\Configuration;
use fivefilters\Readability\ParseException;
use fivefilters\Readability\Readability;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ArticleCrawlerService
{
    private const CACHE_TTL   = 3600; // 1 giờ per URL
    private const MAX_CHARS   = 8000; // tăng lên 8000 để Claude có đủ context
    private const TIMEOUT     = 12;
    private const CONCURRENCY = 5;   // số URLs crawl đồng thời

    private Client $client;

    public function __construct()
    {
        $this->client = new Client([
            'timeout'         => self::TIMEOUT,
            'connect_timeout' => 5,
            'headers'         => [
                'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36',
                'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
                'Accept-Encoding' => 'gzip, deflate',
            ],
            'allow_redirects' => ['max' => 5],
            'verify'          => false, // bỏ qua SSL lỗi trên một số news sites
        ]);
    }

    /**
     * Crawl nhiều URLs SONG SONG bằng Guzzle Pool.
     * Tách cache hit vs cần fetch — chỉ fetch URLs chưa cache.
     */
    public function crawlMany(array $urls): array
    {
        $results  = [];
        $toFetch  = [];

        // Tách URLs đã cache
        foreach ($urls as $url) {
            $hit = Cache::get('crawl:' . md5($url));
            if ($hit !== null) {
                $results[$url] = $hit;
            } else {
                $toFetch[] = $url;
            }
        }

        if (empty($toFetch)) {
            return $results;
        }

        // Crawl song song
        $fetched = $this->poolFetch($toFetch);

        // Lưu cache từng URL
        foreach ($fetched as $url => $content) {
            Cache::put('crawl:' . md5($url), $content, self::CACHE_TTL);
        }

        return array_merge($results, $fetched);
    }

    /**
     * Guzzle Pool — gửi tất cả request đồng thời, concurrency 5.
     */
    private function poolFetch(array $urls): array
    {
        $results  = array_fill_keys($urls, '');
        $requests = function (array $urls) {
            foreach ($urls as $url) {
                yield $url => new Request('GET', $url);
            }
        };

        $pool = new Pool($this->client, $requests($urls), [
            'concurrency' => self::CONCURRENCY,
            'fulfilled'   => function (Response $response, string $url) use (&$results) {
                $html = (string) $response->getBody();
                $results[$url] = $this->extractWithReadability($html, $url);
            },
            'rejected' => function (RequestException $e, string $url) use (&$results) {
                Log::warning('[Crawler] Failed: ' . $url . ' — ' . $e->getMessage());
                $results[$url] = '';
            },
        ]);

        $pool->promise()->wait();

        return $results;
    }

    /**
     * Crawl 1 URL để lấy publish date thật từ meta tags.
     * SerpAPI trả về "3 hours ago" nhưng đó là thời gian cache của họ, không chính xác.
     * Meta tags như article:published_time, datePublished luôn là thời gian thật.
     */
    public function extractPublishDate(string $url): ?string
    {
        try {
            $response = $this->client->get($url);
            $html     = (string) $response->getBody();

            // Thứ tự ưu tiên: meta OG > JSON-LD > meta name > time tag
            $patterns = [
                // Open Graph / Article meta
                '/<meta[^>]+property=["\']article:published_time["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i',
                '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']article:published_time["\'][^>]*>/i',
                // Schema.org
                '/<meta[^>]+itemprop=["\']datePublished["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i',
                // JSON-LD
                '/"datePublished"\s*:\s*"([^"]+)"/i',
                '/"publishedAt"\s*:\s*"([^"]+)"/i',
                // time tag
                '/<time[^>]+datetime=["\']([^"\']+)["\'][^>]*>/i',
            ];

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $html, $m)) {
                    $parsed = strtotime($m[1]);
                    if ($parsed !== false && $parsed > 0) {
                        return date('c', $parsed); // ISO 8601
                    }
                }
            }

        } catch (\Exception $e) {
            Log::debug('[Crawler] extractPublishDate failed: ' . $url . ' — ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Extract nội dung chính bằng fivefilters/readability.php.
     * Dùng thuật toán Firefox Reader Mode — tự động loại ads, nav, sidebar.
     * Fallback về strip_tags nếu Readability parse thất bại.
     */
    private function extractWithReadability(string $html, string $url): string
    {
        if (empty(trim($html))) {
            return '';
        }

        try {
            $config = new Configuration([
                'FixRelativeURLs'     => true,
                'OriginalURL'         => $url,
                'SummonCthulhu'       => false,  // tắt regex hack
                'NormalizeWhitespace' => true,
            ]);

            $readability = new Readability($config);
            $readability->parse($html);

            // Lấy nội dung HTML, rồi strip tags để lấy plain text
            $html = $readability->getContent();
            $text = strip_tags($html ?? '');

            if (! empty(trim($text)) && strlen(trim($text)) > 200) {
                return $this->cleanAndTruncate($text);
            }

        } catch (ParseException $e) {
            Log::debug('[Crawler] Readability parse failed for ' . $url . ': ' . $e->getMessage());
        } catch (\Exception $e) {
            Log::warning('[Crawler] Readability error for ' . $url . ': ' . $e->getMessage());
        }

        // Fallback: strip_tags thủ công nếu Readability thất bại
        return $this->fallbackExtract($html);
    }

    private function fallbackExtract(string $html): string
    {
        // Xóa script/style/nav/footer
        $html = preg_replace(
            '/<(script|style|nav|footer|header|aside|noscript|iframe)[^>]*>.*?<\/\1>/si',
            '',
            $html
        );
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return $this->cleanAndTruncate($text);
    }

    private function cleanAndTruncate(string $text): string
    {
        $text = preg_replace('/\h+/', ' ', $text);
        $text = preg_replace('/(\n\s*){3,}/', "\n\n", $text);
        $text = trim($text);

        if (strlen($text) <= self::MAX_CHARS) {
            return $text;
        }

        $cut  = substr($text, 0, self::MAX_CHARS);
        $last = strrpos($cut, '. ');
        return $last ? substr($cut, 0, $last + 1) . ' [...]' : $cut . ' [...]';
    }
}
