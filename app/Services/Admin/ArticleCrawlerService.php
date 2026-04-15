<?php

namespace App\Services\Admin;

use fivefilters\Readability\Configuration;
use fivefilters\Readability\ParseException;
use fivefilters\Readability\Readability;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ArticleCrawlerService
{
    private const CACHE_TTL   = 3600;
    private const MAX_CHARS   = 20000;
    private const TIMEOUT     = 12;
    private const CONCURRENCY = 5;


    private Client $client;

    public function __construct()
    {
        $this->client = new Client([
            'timeout'         => self::TIMEOUT,
            'connect_timeout' => 5,
            'http_errors'     => false, // 402/403 vào fulfilled thay vì rejected → Jina fallback xử lý được
            'headers'         => [
                'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36',
                'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
                'Accept-Encoding' => 'gzip, deflate',
            ],
            'allow_redirects' => ['max' => 5],
            'verify'          => false,
        ]);
    }

    // ── Public API ────────────────────────────────────────────────────────────

    public function crawlMany(array $urls): array
    {
        $results = [];
        $toFetch = [];

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

        $fetched = $this->poolFetch($toFetch);
        dd($fetched);

        foreach ($fetched as $url => $content) {
            Cache::put('crawl:' . md5($url), $content, self::CACHE_TTL);
        }

        return array_merge($results, $fetched);
    }

    public function extractPublishDate(string $url): ?string
    {
        try {
            $html     = (string) $this->client->get($url)->getBody();
            $patterns = [
                '/<meta[^>]+property=["\']article:published_time["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i',
                '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']article:published_time["\'][^>]*>/i',
                '/<meta[^>]+itemprop=["\']datePublished["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i',
                '/"datePublished"\s*:\s*"([^"]+)"/i',
                '/"publishedAt"\s*:\s*"([^"]+)"/i',
                '/<time[^>]+datetime=["\']([^"\']+)["\'][^>]*>/i',
            ];

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $html, $m)) {
                    $parsed = strtotime($m[1]);
                    if ($parsed !== false && $parsed > 0) {
                        return date('c', $parsed);
                    }
                }
            }
        } catch (\Exception $e) {
            Log::debug('[Crawler] extractPublishDate failed: ' . $url . ' — ' . $e->getMessage());
        }

        return null;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function poolFetch(array $urls): array
    {
        $results  = array_fill_keys($urls, '');
        $blocked  = []; // URLs bị 4xx — xử lý Jina SAU KHI pool xong để tránh nested cURL
        $requests = function (array $urls) {
            foreach ($urls as $url) {
                yield $url => new Request('GET', $url);
            }
        };

        $pool = new Pool($this->client, $requests($urls), [
            'concurrency' => self::CONCURRENCY,
            'fulfilled'   => function (Response $response, string $url) use (&$results, &$blocked) {
                if ($response->getStatusCode() >= 400) {
                    dd(220);
                    // 4xx (402 paywall, 403 blocked) → ghi nhận, xử lý Jina sau khi pool xong
                    $blocked[] = $url;
                } else {
                    dd(33);
                    $results[$url] = $this->extractWithReadability((string) $response->getBody(), $url);
                    dd($results[$url]);

                }
            },
            'rejected' => function (\Throwable $e, string $url) use (&$blocked) {
                Log::warning('[Crawler] Failed: ' . $url . ' — ' . $e->getMessage());
                $blocked[] = $url; // network error → cũng thử Jina
            },
        ]);
        dd($pool);

        $pool->promise()->wait();

        // Xử lý blocked/failed URLs với Jina sau khi pool đã hoàn tất
        foreach ($blocked as $url) {
            $jinaText      = $this->fetchViaJina($url);
            $results[$url] = strlen($jinaText) > 200 ? $jinaText : '';
        }

        return $results;
    }

    /**
     * BƯỚC 1: Xóa HTML elements rác (ads, related, social...) trước Readability.
     * BƯỚC 2: Readability extract nội dung chính (Firefox Reader Mode algorithm).
     * BƯỚC 3: Strip tags → lọc text noise từng dòng.
     */
    private function extractWithReadability(string $html, string $url): string
    {
        if (empty(trim($html))) {
            return '';
        }
        dd($html);
        // Normalize UTF-8: invalid byte sequences → U+FFFD, rồi xóa U+FFFD luôn.
        // Cần làm trước mọi processing vì PCRE /u flag sẽ fail trên invalid UTF-8.
        $html = mb_scrub($html, 'UTF-8');
        $html = str_replace("\xef\xbf\xbd", ' ', $html);

        // Lưu HTML gốc (trước khi strip script) để fallback JSON-LD dùng
        $rawHtml = $html;

        // BƯỚC 1: Xóa elements rác trước khi parse
        $html = $this->removeNoisyElements($html);

        try {
            $config = new Configuration([
                'FixRelativeURLs'     => true,
                'OriginalURL'         => $url,
                'SummonCthulhu'       => false,
                'NormalizeWhitespace' => true,
            ]);

            $readability = new Readability($config);
            $readability->parse($html);

            $extracted = $readability->getContent() ?? '';
            $text      = $this->htmlToText($extracted);

            if (!empty(trim($text)) && strlen(trim($text)) > 200) {
                // BƯỚC 3: Lọc text noise
                return $this->cleanText($text);
            }

        } catch (ParseException $e) {
            Log::debug('[Crawler] Readability parse failed for ' . $url . ': ' . $e->getMessage());
        } catch (\Exception $e) {
            Log::warning('[Crawler] Readability error for ' . $url . ': ' . $e->getMessage());
        }

        // Fallback: thử extract articleBody từ JSON-LD hoặc embedded JSON
        // (dùng cho các site JS-rendered như USA Today, Vox, The Athletic...)
        // Dùng $rawHtml vì $html đã bị strip <script> bởi removeNoisyElements.
        $jsonText = $this->extractFromJsonLd($rawHtml);
        if (strlen($jsonText) > 200) {
            return $jsonText;
        }

        // Fallback: thử fetch AMP version qua Google AMP Cache
        // AMP HTML là static (không cần JS) nên Readability extract được.
        $ampText = $this->fetchAmpVersion($url);
        if (strlen($ampText) > 200) {
            return $ampText;
        }

        // Fallback cuối: Jina AI Reader — xử lý JS-rendered sites phía server.
        // Không cần API key, miễn phí, hoạt động với hầu hết news sites.
        $jinaText = $this->fetchViaJina($url);
        if (strlen($jinaText) > 200) {
            return $jinaText;
        }

        return $this->fallbackExtract($html);
    }

    /**
     * Jina AI Reader (r.jina.ai) — convert bất kỳ URL nào thành clean text.
     * Xử lý JS-rendered pages phía server → hoạt động với React/Vue/SPA sites.
     * Miễn phí, không cần API key. Rate limit: ~20 req/min trên free tier.
     */
    private function fetchViaJina(string $url): string
    {
        try {
            Log::debug('[Crawler] Trying Jina AI Reader', ['url' => $url]);

            // Dùng fresh client riêng — tránh state/handler conflict sau khi pool chạy xong
            $jinaClient = new Client([
                'timeout' => 30,
                'verify'  => false,
                'headers' => [
                    'Accept'    => 'text/plain',
                    'X-Timeout' => '25',
                ],
            ]);

            $response = $jinaClient->get('https://r.jina.ai/' . $url);
            $text     = trim((string) $response->getBody());

            if (strlen($text) < 200) {
                return '';
            }

            // Normalize line endings
            $text = str_replace("\r\n", "\n", $text);

            // Strip Jina metadata header — hỗ trợ cả 3 format:
            // Format 1: "\nMarkdown Content:\n\n" (people.com, phổ biến nhất)
            // Format 2: "\n\n---\n\n"
            // Format 3: regex cũ
            if (str_contains($text, "\nMarkdown Content:\n\n")) {
                $text = substr($text, strpos($text, "\nMarkdown Content:\n\n") + strlen("\nMarkdown Content:\n\n"));
            } elseif (str_contains($text, "\n\n---\n\n")) {
                $text = substr($text, strpos($text, "\n\n---\n\n") + strlen("\n\n---\n\n"));
            } elseif (preg_match('/^(?:Title:|URL Source:|Published Time:).+?(?:\n---+\n\n|\n\n\n)/s', $text, $m)) {
                $text = substr($text, strlen($m[0]));
            }

            $text = trim($text);

            if (strlen($text) < 200) {
                return '';
            }

            Log::debug('[Crawler] Jina AI success', ['url' => $url, 'length' => strlen($text)]);

            // Convert Markdown → HTML paragraphs
            return $this->markdownToHtml($text);

        } catch (\Exception $e) {
            Log::debug('[Crawler] Jina AI failed: ' . $url . ' — ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Convert Markdown text từ Jina → HTML <p> tags.
     * Tự động bỏ nav/menu ở đầu, dừng ở footer.
     */
    private function markdownToHtml(string $markdown): string
    {
        // Normalize line endings
        $markdown = str_replace("\r\n", "\n", $markdown);
        $markdown = str_replace("\r", "\n", $markdown);

        $lines    = explode("\n", $markdown);
        $total    = count($lines);
        $startIdx = 0;

        // Tìm paragraph đầu tiên >= 80 chars SAU KHI đã gặp list item dài (* ...)
        // → bỏ qua nav/author bio ở đầu trang (people.com pattern)
        // Fallback: nếu không có list nào → dùng paragraph đầu tiên
        $seenList      = false;
        $firstFallback = -1;

        for ($i = 0; $i < $total; $i++) {
            $t = trim($lines[$i]);

            // Chỉ đếm list item dài (>= 40 chars) — bỏ qua nav items ngắn như "* Home", "* Celebrity"
            if (preg_match('/^[*\-]\s+\S/', $t) && strlen($t) >= 40) $seenList = true;

            if (strlen($t) >= 80
                && !preg_match('/^[*\-]\s+/', $t)
                && !preg_match('/^#{1,6}\s+/', $t)
                && !preg_match('/^(\[[^\]]*\]\(https?:\/\/[^)]+\))+\s*$/', $t)) {

                if ($firstFallback === -1) $firstFallback = $i;

                if ($seenList) {
                    $startIdx = $i;
                    for ($j = $i - 1; $j >= max(0, $i - 20); $j--) {
                        $lj = trim($lines[$j]);
                        if (preg_match('/^[*\-]\s+/', $lj)) break; // stop: don't cross back over bullet list
                        if (preg_match('/^#{1,6}\s+/', $lj)) {
                            $startIdx = $j;
                            break;
                        }
                    }
                    break;
                }
            }
        }

        // Fallback: không có list → dùng paragraph đầu tiên
        if ($startIdx === 0 && $firstFallback > 0) {
            $startIdx = $firstFallback;
            for ($j = $firstFallback - 1; $j >= max(0, $firstFallback - 20); $j--) {
                $lj = trim($lines[$j]);
                if (preg_match('/^[*\-]\s+/', $lj)) break; // stop: don't cross back over bullet list
                if (preg_match('/^#{1,6}\s+/', $lj)) {
                    $startIdx = $j;
                    break;
                }
            }
        }

        $lines            = array_slice($lines, $startIdx);
        $result           = [];
        $foundBody        = false;
        $prevLineWasImage = false;
        $skipLines        = 0; // skip N non-empty lines (for inline widgets like Related Stories)

        // Hard stop: real end of article — break immediately
        $stopPattern = '/^(Follow Us|Newsletter Sign Up|About Us|Privacy Policy|Terms of Service|Advertise)\s*[:\-]?\s*$/i';
        // Skip block: inline widget — skip heading + next 3 non-empty lines, then continue
        $skipPattern = '/^(Read More:|Trending Now|Trending Stories|Related Articles|Related Stories)\s*[:\-]?\s*$/i';

        foreach ($lines as $line) {
            $t = trim($line);

            // Check stop/skip against both plain line and heading text (## Related Stories)
            $checkT = preg_match('/^#{1,6}\s+(.+)/', $t, $hm) ? trim($hm[1]) : $t;
            if ($foundBody && $checkT !== '' && preg_match($stopPattern, $checkT)) break;
            if ($foundBody && $checkT !== '' && preg_match($skipPattern, $checkT)) {
                $skipLines = 3; // skip widget heading + related titles
                continue;
            }

            // Skip lines belonging to the inline widget block
            if ($skipLines > 0) {
                if ($t !== '') $skipLines--;
                continue;
            }

            if ($t === '') { $result[] = ''; continue; } // blank line — keep $prevLineWasImage

            // Detect image line before processing
            $isImageLine = (bool) preg_match('/^!\[/', $t);

            // Skip image caption: short non-heading line right after image (blank lines allowed between)
            if ($prevLineWasImage && !$isImageLine && strlen($t) < 100
                && !preg_match('/^[*\-#]/', $t)) {
                $prevLineWasImage = false;
                continue;
            }

            if (!$foundBody && strlen($t) >= 80 && !preg_match('/^[*\-#]/', $t)) {
                $foundBody = true;
            }

            if (preg_match('/^#{1,6}\s+(.+)/', $t, $m)) {
                $prevLineWasImage = false;
                $result[] = '';
                $result[] = '<h2>' . htmlspecialchars(trim($m[1])) . '</h2>';
                $result[] = '';
                continue;
            }

            if (preg_match('/^[-*_]{3,}$/', $t)) { $prevLineWasImage = false; $result[] = ''; continue; }

            // Fix: dùng regex xử lý URL có nested parens như :maxbytes(150000)
            $t = preg_replace('/!\[[^\]]*\]\((?:[^()]*|\([^()]*\))*\)/', '', $t);
            $t = preg_replace('/\[([^\]]+)\]\((?:[^()]*|\([^()]*\))*\)/', '$1', $t);
            $t = preg_replace('/(\*{1,2}|_{1,2})([^*_]+)\1/', '$2', $t);
            $t = preg_replace('/\[[^\]]*\]\(https?:\/\/[^)]*\)/', '', $t);
            $t = trim($t);

            if ($t === '') {
                $prevLineWasImage = $isImageLine; // pure image line → next line may be caption
                continue;
            }
            // CDN filter SAU substitution — catch phần URL còn sót
            if (preg_match('/^:(?:maxbytes|max_bytes|stripicc|strip_icc|format|focal|fill|resize)\(/i', $t)) continue;
            if (strlen($t) < 8) continue;
            if (preg_match('/^https?:\/\/\S+$/', $t)) continue;
            if (preg_match('/^©|\bcopyright\b|\ball rights reserved\b/i', $t)) continue;
            if (preg_match('/^(Leave a Comment|People Editorial Guidelines|Sign Up for Newsletter)\s*$/i', $t)) continue;
            if (preg_match('/^Published (on|at) .+\d{4}/i', $t)) continue;
            if (preg_match('/^Our new app is here/i', $t)) continue;
            if (preg_match('/\bCredit\s*:/i', $t)) continue;
            if (preg_match('/^Never miss a story/i', $t)) continue; // newsletter promo
            // Author bio: "Ashlyn Robinette is a Weddings Writer at PEOPLE..."
            if (preg_match('/\bis (?:a|an) .{2,40}(?:Writer|Editor|Reporter|Correspondent|Contributor) at /i', $t)) continue;
            // Lọc dòng chỉ là tên người (photographer credit): "Greg Finck"
            if (preg_match('/^[A-Z][a-zA-Z]+(?: [A-Z][a-zA-Z]+){0,2}$/', $t) && strlen($t) < 35) continue;

            $prevLineWasImage = false;
            $result[] = $t;
        }

        // Ghép thành HTML paragraphs
        $html  = '';
        $paras = preg_split('/\n{2,}/', implode("\n", $result));
        foreach ($paras as $para) {
            $para = trim($para);
            if (empty($para)) continue;
            if (str_starts_with($para, '<h2>')) { $html .= $para; continue; }
            $html .= '<p>' . nl2br(htmlspecialchars($para)) . '</p>';
        }

        // Giới hạn độ dài
        if (strlen($html) > self::MAX_CHARS) {
            $cut  = substr($html, 0, self::MAX_CHARS);
            $last = strrpos($cut, '</p>');
            $html = $last ? substr($cut, 0, $last + 4) : $cut;
        }

        return $html;
    }

    /**
     * Thử fetch AMP version của URL qua Google AMP Cache.
     * Google AMP Cache lưu bản AMP của các trang báo lớn — static HTML, không cần JS.
     *
     * Format: https://[domain-encoded].cdn.ampproject.org/v/s/[original-url]
     * Encoding: domain = thay "." → "-", "-" → "--"
     * Ví dụ: www.usatoday.com → www-usatoday-com
     */
    private function fetchAmpVersion(string $url): string
    {
        $parsed = parse_url($url);
        if (empty($parsed['host'])) {
            return '';
        }

        // Encode domain theo chuẩn AMP cache: "-" → "--", "." → "-"
        $domain        = $parsed['host'];
        $encoded       = str_replace('.', '-', str_replace('-', '--', $domain));
        $withoutScheme = preg_replace('#^https?://#', '', $url);
        $ampCacheUrl   = 'https://' . $encoded . '.cdn.ampproject.org/v/s/' . $withoutScheme;

        try {
            Log::debug('[Crawler] Trying AMP cache', ['amp_url' => $ampCacheUrl]);

            $response = $this->client->get($ampCacheUrl, ['timeout' => 15]);
            $ampHtml  = (string) $response->getBody();

            if (strlen($ampHtml) < 500) {
                return '';
            }

            $ampHtml = mb_scrub($ampHtml, 'UTF-8');
            $ampHtml = str_replace("\xef\xbf\xbd", ' ', $ampHtml);

            // AMP HTML thường extract tốt với Readability
            $config = new Configuration([
                'FixRelativeURLs'     => true,
                'OriginalURL'         => $url,
                'NormalizeWhitespace' => true,
            ]);
            $readability = new Readability($config);
            $readability->parse($this->removeNoisyElements($ampHtml));

            $text = $this->htmlToText($readability->getContent() ?? '');
            if (strlen(trim($text)) > 200) {
                Log::debug('[Crawler] AMP cache success', ['url' => $url, 'length' => strlen($text)]);
                return $this->cleanText($text);
            }

        } catch (\Exception $e) {
            Log::debug('[Crawler] AMP cache failed: ' . $url . ' — ' . $e->getMessage());
        }

        return '';
    }

    /**
     * Extract articleBody từ JSON-LD (<script type="application/ld+json">) hoặc
     * embedded JSON blob trong <script> bất kỳ.
     * Dùng làm fallback cho các site load content bằng JS.
     */
    private function extractFromJsonLd(string $html): string
    {
        // 1. Thử parse từng <script type="application/ld+json"> bằng json_decode
        //    (robust hơn regex: xử lý @graph, escaped chars, Unicode đúng chuẩn)
        preg_match_all(
            '/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/si',
            $html,
            $scripts
        );

        foreach ($scripts[1] as $json) {
            $data = json_decode(trim($json), true);
            if (!is_array($data)) continue;

            // Flatten @graph array (Google, USA Today, nhiều site dùng @graph)
            $nodes = isset($data['@graph']) ? $data['@graph'] : [$data];

            foreach ($nodes as $node) {
                if (!empty($node['articleBody']) && strlen($node['articleBody']) > 200) {
                    Log::debug('[Crawler] JSON-LD articleBody found via json_decode', [
                        'length' => strlen($node['articleBody']),
                    ]);
                    return $this->cleanText($this->htmlToText($node['articleBody']));
                }
            }
        }

        // 2. Fallback: regex tìm "articleBody" trong bất kỳ <script> nào (embedded JS state)
        //    Dùng cho các site nhúng state vào window.__INITIAL_STATE__ hoặc tương tự
        if (preg_match('/"articleBody"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/s', $html, $m)) {
            $body = json_decode('"' . $m[1] . '"');
            if (is_string($body) && strlen($body) > 200) {
                Log::debug('[Crawler] JSON-LD articleBody found via regex', [
                    'length' => strlen($body),
                ]);
                return $this->cleanText($this->htmlToText($body));
            }
        }

        Log::debug('[Crawler] JSON-LD not found', [
            'has_ld_json'    => str_contains($html, 'application/ld+json'),
            'has_articleBody'=> str_contains($html, 'articleBody'),
        ]);

        return '';
    }

    /**
     * Xóa HTML tag rác trước khi Readability parse.
     * Chỉ xóa các tag an toàn — không dùng class/id regex vì dễ xóa nhầm nội dung.
     */
    private function removeNoisyElements(string $html): string
    {
        // Xóa các tag block hoàn toàn — không bao giờ chứa nội dung bài viết
        return preg_replace(
            '/<(script|style|noscript|iframe|svg|canvas|form)[^>]*>.*?<\/\1>/si',
            '',
            $html
        );
    }

    /**
     * Lọc text noise từng dòng sau khi strip_tags.
     * Loại bỏ: quảng cáo, subscribe, social share, breadcrumb, related links...
     */
    private function cleanText(string $text): string
    {
        $lines           = explode("\n", $text);
        $result          = [];
        $skipSection     = false; // Bỏ toàn bộ section sau heading noise

        foreach ($lines as $line) {
            $line = trim($line);

            // Xóa ký tự ◆ ♦ ◇ ● ▪ ▸ ► inline trong dòng trước khi check
            $line = preg_replace('/[\x{25A0}-\x{25FF}\x{2600}-\x{27BF}]/u', '', $line);
            $line = str_replace(['◆', '◇', '♦', '●', '▸', '▪', '►', '◊'], '', $line);
            $line = trim($line);

            $lower = strtolower($line);
            $len   = strlen($line);

            // Bỏ dòng rỗng (sẽ xử lý lại sau)
            if ($len === 0) {
                $result[] = '';
                continue;
            }

            // Bỏ dòng quá ngắn (dưới 8 ký tự) — thường là UI fragment ("|", "NFL", "Share"...)
            // Ngưỡng 8 thay vì 15 để giữ tên ngắn như "Bo Melton" (9), "Skyy Moore" (10)
            // List items ("- ...") luôn được giữ dù ngắn hơn ngưỡng
            $isListItem = str_starts_with($line, '- ');
            if ($len < 8 && !preg_match('/[.!?]$/', $line) && !$isListItem) {
                continue;
            }

            // Bỏ dòng chứa noise keywords
            if (preg_match('/\b(advertisement|sponsored content|paid content|sponsored by|brought to you by)\b/i', $line)) {
                continue;
            }

            // Bỏ "Article continues below this ad" và các biến thể
            if (preg_match('/article continues below|continues below this ad|below this ad/i', $lower)) {
                continue;
            }

            // Bỏ "Make X a preferred source" / "Add Preferred Source" / paywalls
            if (preg_match('/preferred source|add preferred|become a member|subscriber only|subscription required|sign in to read/i', $lower)) {
                continue;
            }

            // Bỏ subscribe / newsletter prompts
            if (preg_match('/\b(subscribe|sign up|newsletter|get the latest|join our|follow us|follow on|like us on|find us on|connect with us)\b/i', $lower)
                && $len < 150) {
                continue;
            }

            // Bỏ social share prompts
            if (preg_match('/\b(share this|share on|tweet this|click to share|copy link|send email)\b/i', $lower)) {
                continue;
            }

            // Bỏ cookie / GDPR banners
            if (preg_match('/\b(cookie|privacy policy|terms of service|gdpr|we use cookies|by continuing)\b/i', $lower)
                && $len < 200) {
                continue;
            }

            // Bỏ breadcrumb / navigation dạng "Home > Sports > NFL"
            if (preg_match('/^[\w\s]+(?:\s*[>\/|]\s*[\w\s]+){2,}$/', $line) && $len < 100) {
                continue;
            }

            // Bỏ dòng chỉ toàn URL
            if (preg_match('/^https?:\/\/\S+$/', $line)) {
                continue;
            }

            // Bỏ copyright lines
            if (preg_match('/^©|\bcopyright\b|\ball rights reserved\b/i', $lower)) {
                continue;
            }

            // Bỏ "Read more:", "Also read:", "Related:", "More:", "More NFL news:", "MORE:" v.v.
            if (preg_match('/^(read more|also read|related|see also|more from|more news|more:|trending now|up next|more nfl|more nba|more mlb|more nhl|more sports|more on|watch:|listen:)/i', $lower)) {
                continue;
            }

            // Bỏ heading marker chứa noise → bật skipSection để bỏ cả phần sau
            if (preg_match('/^##heading##(more|related|trending|up next|watch|listen)/i', $lower)) {
                $skipSection = true;
                continue;
            }

            // Gặp heading thực sự → tắt skipSection
            if (preg_match('/^##heading##/i', $lower)) {
                $skipSection = false;
            }

            // Bỏ toàn bộ section sau heading noise (More NFL news, Related, v.v.)
            if ($skipSection) {
                continue;
            }

            // Bỏ photo credit / caption ảnh
            if (preg_match('/\b(getty|ap photo|reuters|afp|shutterstock|wire image|photo by|image by|credit:|imagn)\b/i', $lower)) {
                continue;
            }

            // Bỏ author attribution dạng "Reporter / Senior Staff Writer", "Staff Writer | Sports"
            if (preg_match('/\b(reporter|staff writer|senior writer|contributing writer|correspondent|columnist)\b/i', $lower)
                && preg_match('/[\/|]/', $line) && $len < 100) {
                continue;
            }

            // Bỏ author bio: "Name is a sports writer who covers..." hoặc "This is my Nth year covering..."
            if (preg_match('/\b(is a (sports|news|staff|senior|contributing|freelance) (writer|reporter|editor|contributor))\b/i', $lower)) {
                continue;
            }
            if (preg_match('/^(this is my \d+(st|nd|rd|th) year|i(\'ve| have) been covering|i cover the)/i', $lower)) {
                continue;
            }

            // Bỏ "More about [Name]"
            if (preg_match('/^more about\s+\w/i', $lower)) {
                continue;
            }

            // Bỏ related article list: nhiều tiêu đề nối bằng " - " (dạng "A - B - C - D")
            if (substr_count($line, ' - ') >= 2 && $len > 80) {
                continue;
            }

            // Bỏ navigation link list nối bằng " | " (dạng SI.com: "Title A | Title B | Title C | ...")
            if (substr_count($line, ' | ') >= 2) {
                continue;
            }

            $result[] = $line;
        }

        // Xóa heading không có nội dung phía sau (orphaned heading).
        // Ví dụ: "##HEADING##Packers Predraft Visits##/HEADING##" mà phía sau chỉ toàn dòng trắng
        // hoặc heading khác → xóa để tránh tốn token khi truyền cho Claude.
        $filtered = [];
        $total    = count($result);
        for ($i = 0; $i < $total; $i++) {
            if (!str_starts_with($result[$i], '##HEADING##')) {
                $filtered[] = $result[$i];
                continue;
            }
            // Tìm dòng nội dung tiếp theo (không rỗng, không phải heading khác)
            $hasContent = false;
            for ($j = $i + 1; $j < $total; $j++) {
                $next = trim($result[$j]);
                if ($next === '') continue;
                if (str_starts_with($next, '##HEADING##')) break; // heading khác → orphaned
                $hasContent = true;
                break;
            }
            if ($hasContent) {
                $filtered[] = $result[$i];
            }
        }

        $text = implode("\n", $filtered);

        // Gom nhiều dòng trắng liên tiếp → 1 dòng trắng
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = preg_replace('/\h+/u', ' ', $text); // cần /u để match U+00A0 đúng UTF-8
        $text = trim($text);

        return $this->truncate($text);
    }

    private function fallbackExtract(string $html): string
    {
        $html = preg_replace(
            '/<(script|style|nav|footer|header|aside|noscript|iframe|form)[^>]*>.*?<\/\1>/si',
            '',
            $html
        );

        return $this->cleanText($this->htmlToText($html));
    }

    /**
     * Convert HTML → plain text có giữ paragraph breaks.
     * strip_tags() đơn thuần xóa tags nhưng không giữ newlines.
     */
    private function htmlToText(string $html): string
    {
        // Xóa figure/figcaption — caption ảnh, credit ảnh
        $html = preg_replace('/<figure[^>]*>.*?<\/figure>/si', '', $html);
        $html = preg_replace('/<figcaption[^>]*>.*?<\/figcaption>/si', '', $html);

        // Giữ heading — đánh dấu trước khi strip tags
        $html = preg_replace_callback('/<h([1-6])[^>]*>(.*?)<\/h\1>/si', function ($m) {
            $text = strip_tags($m[2]);
            return "\n\n##HEADING##" . trim($text) . "##/HEADING##\n\n";
        }, $html);

        // List items → "- item" + single newline
        // KHÔNG thêm \n trước "- " — để các items trong cùng <ul> nằm trên cùng 1 paragraph
        // sau khi split("\n{2,}") trong toHtml(). Thứ tự: <ul>→\n, <li>→"- ", </li>→\n → "- item\n"
        $html = preg_replace('/<li[^>]*>/i', '- ', $html);
        $html = preg_replace('/<\/li>/i', "\n", $html);

        // ul/ol → double newline để tách khỏi paragraph trước/sau
        $html = preg_replace('/<ul[^>]*>/i', "\n\n", $html);
        $html = preg_replace('/<\/ul>/i', "\n\n", $html);
        $html = preg_replace('/<ol[^>]*>/i', "\n\n", $html);
        $html = preg_replace('/<\/ol>/i', "\n\n", $html);

        // Block elements → newline (opening) + double newline (closing)
        $html = preg_replace('/<(?:p|div|section|article|blockquote)[^>]*>/i', "\n", $html);
        $html = preg_replace('/<\/(p|div|section|article|blockquote)>/i', "\n\n", $html);

        // Inline breaks → single newline
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html);

        // Adjacent links (e.g. depth-chart player names) — Readability có thể gộp chúng thành
        // <a>Name1</a><a>Name2</a> mà không có separator. Tách ra bằng newline.
        $html = preg_replace('/<\/a>\s*<a\b/i', "</a>\n<a", $html);

        // Strip còn lại
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Xóa U+FFFD (Replacement Character — xuất hiện khi encoding lỗi trong pipeline)
        $text = str_replace("\xef\xbf\xbd", ' ', $text);

        // Whitelist: chỉ giữ lại ký tự Latin + newline + dấu câu thông dụng.
        // Loại bỏ toàn bộ symbol/icon Unicode (◆ ♦ ● ■ emoji v.v.) bằng cách thay = khoảng trắng.
        // Giữ: \t \n \r | ASCII printable | Latin Extended | dấu gạch ngang | nháy | chấm lửng
        $text = preg_replace(
            '/[^\x{0009}\x{000A}\x{000D}\x{0020}-\x{007E}\x{00A0}-\x{024F}\x{2010}-\x{2015}\x{2018}-\x{201D}\x{2026}]/u',
            ' ',
            $text
        );

        // Gộp nhiều khoảng trắng ngang (không xóa newline)
        $text = preg_replace('/[^\S\n]+/', ' ', $text);

        return $text;
    }

    private function truncate(string $text): string
    {
        if (strlen($text) <= self::MAX_CHARS) {
            return $this->toHtml($text);
        }

        $cut  = substr($text, 0, self::MAX_CHARS);
        $last = strrpos($cut, '. ');
        $text = $last ? substr($cut, 0, $last + 1) . ' [...]' : $cut . ' [...]';

        return $this->toHtml($text);
    }

    /**
     * Convert plain text → HTML <p> tags.
     * Mỗi đoạn (cách nhau bằng dòng trắng) → 1 thẻ <p>.
     */
    private function toHtml(string $text): string
    {
        $paragraphs    = preg_split('/\n{2,}/', trim($text));
        $html          = '';
        $seenParagraph = false; // Đã thấy <p> content chưa (để detect highlights ở đầu bài)

        foreach ($paragraphs as $para) {
            $para = trim($para);
            if (empty($para)) continue;

            // Heading marker → <h2>
            if (preg_match('/^##HEADING##(.+)##\/HEADING##$/s', $para, $m)) {
                $html .= '<h2>' . htmlspecialchars(trim($m[1])) . '</h2>';
                continue;
            }

            // Bỏ caption ảnh: dòng chứa " | " dạng "Tên ảnh | Photographer Name"
            if (preg_match('/\|\s*\w+.{0,60}(Images?|Photo|Getty|Reuters|AFP|AP|Imagn)/i', $para)
                && strlen($para) < 200) {
                continue;
            }

            // Bỏ credit line ngắn dạng "Getty Images", "AP Photo by..."
            if (preg_match('/^(Getty|AP|Reuters|AFP|Imagn|Shutterstock|USA Today|Icon Sportswire)/i', $para)
                && strlen($para) < 120) {
                continue;
            }

            // List block: para chứa nhiều dòng bắt đầu bằng "- "
            $lines        = explode("\n", $para);
            $nonEmpty     = array_values(array_filter($lines, fn($l) => trim($l) !== ''));
            $listLines    = array_filter($nonEmpty, fn($l) => str_starts_with(trim($l), '- '));

            if (count($nonEmpty) >= 2 && count($listLines) >= 2) {
                // Bỏ "highlights/key points" block: list đầu tiên xuất hiện trước bất kỳ <p> nào
                // và tất cả items là câu hoàn chỉnh (kết thúc ".") — đây là summary thừa.
                if (!$seenParagraph) {
                    $allSentences = count(array_filter(
                        $nonEmpty,
                        fn($l) => preg_match('/[.!?]\s*$/', trim(preg_replace('/^-\s+/', '', $l)))
                    )) === count($nonEmpty);

                    if ($allSentences && count($nonEmpty) <= 6) {
                        continue; // skip highlights
                    }
                }

                $html .= '<ul>';
                foreach ($nonEmpty as $line) {
                    $line = trim($line);
                    $item = preg_replace('/^-\s+/', '', $line);
                    $html .= '<li>' . htmlspecialchars($item) . '</li>';
                }
                $html .= '</ul>';
                continue;
            }

            // Dòng đơn bắt đầu bằng "- " → <ul><li> đơn
            if (str_starts_with(trim($para), '- ')) {
                $item = preg_replace('/^-\s+/', '', trim($para));
                $html .= '<ul><li>' . htmlspecialchars($item) . '</li></ul>';
                continue;
            }

            // Multi-line para với tất cả các dòng ngắn (≤ 40 chars, không dấu câu cuối) →
            // có thể là danh sách tên cầu thủ / roster từ adjacent <a> tags — render thành <ul>
            $shortLines = array_filter($nonEmpty, fn($l) => strlen(trim($l)) <= 40 && !preg_match('/[.!?,]$/', trim($l)));
            if (count($nonEmpty) >= 3 && count($shortLines) === count($nonEmpty)) {
                $html .= '<ul>';
                foreach ($nonEmpty as $line) {
                    $html .= '<li>' . htmlspecialchars(trim($line)) . '</li>';
                }
                $html .= '</ul>';
                continue;
            }

            // Inline newlines trong <p> → <br> để giữ line breaks khi hiển thị
            $paraHtml      = nl2br(htmlspecialchars($para));
            $seenParagraph = true;
            $html .= '<p>' . $paraHtml . '</p>';
        }

        return $html;
    }
}
