<?php
// app/Services/Admin/SerpApiService.php

namespace App\Services\Admin;

use App\Models\NewsSource;
use App\Models\NewsWeb;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SerpApiService
{
    protected Client $client;
    protected string $apiKey;

    protected function trustedSources(string $categoryId = ''): array
    {
        $key = 'news_webs_sources_' . ($categoryId ?: 'all');
        return Cache::remember($key, 3600, fn() =>
            NewsWeb::where('is_active', true)
                ->when($categoryId, fn($q) => $q->where('category_id', $categoryId))
                ->get(['domain', 'base_url'])
                ->map(fn($r) => ['domain' => $r->domain, 'base_url' => $r->base_url])
                ->toArray()
        );
    }

    protected function blockedSources(): array
    {
        return Cache::remember('news_webs_blocked', 3600, fn() =>
            NewsWeb::where('is_blocked', true)->pluck('domain')->toArray()
        );
    }

    public function __construct()
    {
        $this->apiKey = config('services.serpapi.key');

        $this->client = new Client([
            'timeout'         => 15,
            'connect_timeout' => 5,
            'headers'         => ['Accept' => 'application/json'],
        ]);
    }

    // ══════════════════════════════════════════════
    // NEWS — 1 call/query, cache 15 phút
    // ══════════════════════════════════════════════

    public function searchNews(string $query, int $limit = 10): array
    {
        // v2: thêm version vào cache key để force refresh khi logic thay đổi
        $cacheKey = 'serp_news_v2_' . md5($query);

        return Cache::remember($cacheKey, 900, function () use ($query, $limit) { // 15 phút
            return $this->withRetry(function () use ($query, $limit) {
                $response = $this->client->get('https://serpapi.com/search', [
                    'query' => [
                        'engine'  => 'google_news',
                        'q'       => $query,
                        'gl'      => 'us',
                        'hl'      => 'en',
                        'num'     => $limit,
                        'api_key' => $this->apiKey,
                    ]
                ]);

                $data    = json_decode($response->getBody(), true);
                $results = $data['news_results'] ?? [];

                $hasStories = collect($results)->filter(fn($r) => !empty($r['stories']))->count();
                Log::info("SerpAPI News [{$query}]: " . count($results) . " results, {$hasStories} clusters");

                // Expand clusters: 1 cluster → nhiều bài riêng lẻ
                // Ví dụ: "Cowboys on George Pickens" → 3 bài (NFL.com, Landry Hat, SI)
                // Mỗi bài trong cluster đều được gán top_story=true + stories_count=N
                $expanded = [];
                foreach ($results as $item) {
                    $stories      = $item['stories'] ?? [];
                    $storiesCount = count($stories);

                    if ($storiesCount > 0) {
                        // Cluster → expand từng bài riêng
                        Log::info(sprintf("SerpAPI News [%s]: cluster '%s' → %d stories", $query, $item['title'] ?? '', $storiesCount));

                        foreach ($stories as $story) {
                            $link = $story['link'] ?? '';
                            if (empty($link)) continue;

                            $expanded[] = [
                                'title'       => $story['title']            ?? $item['title'] ?? '',
                                'link'        => $link,
                                'snippet'     => $story['snippet']          ?? $item['snippet'] ?? '',
                                'source'      => $story['source']['name']   ?? $item['source']['name'] ?? '',
                                'source_icon' => $story['source']['icon']   ?? $item['source']['icon'] ?? '',
                                'date'        => $story['iso_date']         ?? $story['date'] ?? $item['iso_date'] ?? $item['date'] ?? '',
                                'position'    => $item['position'] ?? 99,
                                'thumbnail'   => $story['thumbnail']        ?? $item['thumbnail'] ?? '',
                                'stories'     => $stories,
                                'top_story'   => true,
                            ];
                        }
                    } else {
                        // Bài đơn lẻ — giữ nguyên
                        $link = $item['link'] ?? '';
                        if (empty($link)) continue;

                        $expanded[] = [
                            'title'       => $item['title']          ?? '',
                            'link'        => $link,
                            'snippet'     => $item['snippet']        ?? '',
                            'source'      => $item['source']['name'] ?? '',
                            'source_icon' => $item['source']['icon'] ?? '',
                            'date'        => $item['iso_date']       ?? $item['date'] ?? '',
                            'position'    => $item['position']       ?? 99,
                            'thumbnail'   => $item['thumbnail']      ?? '',
                            'stories'     => [],
                            'top_story'   => false,
                        ];
                    }
                }

                Log::info("SerpAPI News [{$query}]: " . count($expanded) . " expanded articles");
                return $expanded;
            }, "News[{$query}]");
        });
    }

    // ══════════════════════════════════════════════
    // RECENT — top N bài mới nhất, lọc nhẹ (no video, no blocked)
    // ══════════════════════════════════════════════

    public function filterRecent(array $articles, int $limit = 20, string $categoryId = ''): array
    {
        $trustedWebs    = $this->trustedSources($categoryId);
        $trustedSources = NewsSource::trustedDomains();

        $seen        = [];
        $fromWebs    = [];
        $fromSources = [];

        foreach ($articles as $article) {
            $link  = $article['link'] ?? '';
            $title = $article['title'] ?? '';
            if (empty($link) || empty($title)) continue;
            if (isset($seen[$link])) continue;
            $seen[$link] = true;

            $domain = strtolower(parse_url($link, PHP_URL_HOST) ?? '');

            if (!empty($trustedWebs) && $this->matchesTrusted($domain, $trustedWebs)) {
                $fromWebs[] = $article;
            } elseif (!empty($trustedSources) && $this->matchesDomain($domain, $trustedSources)) {
                $fromSources[] = $article;
            }
        }

        // Case 1: news_webs có đủ limit bài trong 24h → chỉ dùng news_webs
        // Case 2: không đủ → fill thêm từ news_sources cho đủ limit
        $websRecent = array_filter($fromWebs, fn($a) => $this->isWithinHours($a['date'] ?? '', 24));

        $result = count($websRecent) >= $limit
            ? array_values($websRecent)
            : array_merge(array_values($websRecent), $fromSources);

        usort($result, fn($a, $b) =>
            $this->parseDateToTimestamp($b['date'] ?? '') <=> $this->parseDateToTimestamp($a['date'] ?? '')
        );

        return array_values(array_slice($result, 0, $limit));
    }

    private function matchesDomain(string $domain, array $domains): bool
    {
        foreach ($domains as $d) {
            if (str_contains($domain, $d)) return true;
        }
        return false;
    }

    private function parseDateToTimestamp(string $date): int
    {
        if (empty($date)) return 0;
        $d = strtolower(trim($date));

        // Relative strings (cũ — giữ để tương thích)
        if (str_contains($d, 'minute')) return time() - 30 * 60;
        if (preg_match('/^(\d+)\s+hour/', $d, $m)) return time() - (int)$m[1] * 3600;
        if (preg_match('/^1 day/', $d)) return time() - 86400;
        if (preg_match('/^(\d+) day/', $d, $m)) return time() - (int)$m[1] * 86400;

        // ISO 8601: "2026-04-27T02:08:00Z" hoặc absolute date
        $parsed = strtotime($date);
        return $parsed !== false ? $parsed : 0;
    }

    // ══════════════════════════════════════════════
    // RETRY — exponential backoff cho network errors
    // ══════════════════════════════════════════════

    private function withRetry(callable $fn, string $context = '', int $maxAttempts = 3): array
    {
        $delays = [0, 5, 15]; // giây: lần 1 ngay, lần 2 chờ 5s, lần 3 chờ 15s

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            if ($attempt > 0) {
                sleep($delays[$attempt]);
                Log::info("SerpAPI {$context}: retry attempt {$attempt}");
            }

            try {
                return $fn();
            } catch (RequestException $e) {
                $status = $e->getResponse()?->getStatusCode();

                // 401/403/422: lỗi config, không retry
                if (in_array($status, [401, 403, 422])) {
                    Log::error("SerpAPI {$context}: fatal error {$status} — " . $e->getMessage());
                    return [];
                }

                // 429 Rate limit hoặc 5xx server error: retry
                Log::warning("SerpAPI {$context}: attempt {$attempt} failed ({$status}) — " . $e->getMessage());

                if ($attempt === $maxAttempts - 1) {
                    Log::error("SerpAPI {$context}: all {$maxAttempts} attempts failed");
                    return [];
                }
            } catch (\Exception $e) {
                Log::warning("SerpAPI {$context}: attempt {$attempt} exception — " . $e->getMessage());

                if ($attempt === $maxAttempts - 1) {
                    Log::error("SerpAPI {$context}: all {$maxAttempts} attempts failed");
                    return [];
                }
            }
        }

        return [];
    }

    // ══════════════════════════════════════════════
    // FILTER + SCORE — universal cho mọi domain
    // ══════════════════════════════════════════════

    public function scoreArticle(array $article): array
    {
        $domain = strtolower(parse_url($article['link'] ?? '', PHP_URL_HOST) ?? '');
        return [
            'quality_score' => $this->calcScore($article, $domain, []),
            'fb_score'      => $this->calcFbScore($article),
        ];
    }

    public function filterAndScore(array $articles): array
    {
        $result = $this->doFilterAndScore($articles, 12, [], []);

        if (count($result) < 10) {
            Log::info(sprintf('[FilterAndScore] Only %d in 12h, expanding to 24h', count($result)));
            $result = $this->doFilterAndScore($articles, 24, [], []);
        }

        Log::info(sprintf('[FilterAndScore] Final: %d articles', count($result)));
        return $result;
    }

    private function doFilterAndScore(array $articles, int $hours, array $trusted, array $blocked): array
    {

        $scored = [];
        $seen   = [];

        foreach ($articles as $article) {
            $link     = $article['link'] ?? '';
            $title    = $article['title'] ?? '';
            $date     = $article['date'] ?? '';
            $topStory = !empty($article['stories']);

            if (empty($link) || empty($title)) continue;
            if (isset($seen[$link])) continue;
            $seen[$link] = true;

            $maxHours = $topStory ? 12 : $hours;
            if (!empty($date) && !$this->isWithinHours($date, $maxHours)) continue;

            $domain = strtolower(parse_url($link, PHP_URL_HOST) ?? '');

            foreach ($blocked as $b) {
                if (str_contains($domain, $b)) continue 2;
            }

            if ($this->isVideoContent($title, $link)) continue;

            $score   = $this->calcScore($article, $domain, $trusted);
            $fbScore = $this->calcFbScore($article);

            $scored[] = array_merge($article, [
                'quality_score' => $score,
                'fb_score'      => $fbScore,
                'domain'        => $domain,
            ]);
        }

        usort($scored, fn($a, $b) => $b['quality_score'] <=> $a['quality_score']);
        return array_values($scored);
    }

    // ══════════════════════════════════════════════
    // HELPERS
    // ══════════════════════════════════════════════

    private function matchesTrusted(string $domain, array $trusted): bool
    {
        foreach ($trusted as $source) {
            if (str_contains($domain, $source['domain'] ?? '')) return true;
        }
        return false;
    }

    private function calcScore(array $article, string $domain, array $trusted = []): int
    {
        $score    = 0;
        $position = (int)($article['position'] ?? 99);
        $stories  = count($article['stories'] ?? []);
        $topStory = !empty($article['stories']);

        // ── CLUSTER SIZE — tín hiệu mạnh nhất của Google News ────────────────
        if ($topStory) {
            $score += match(true) {
                $stories >= 10 => 60,
                $stories >= 5  => 45,
                $stories >= 3  => 30,
                $stories >= 2  => 20,
                default        => 10,
            };
        }

        // ── POSITION — thứ hạng Google News tự chọn ──────────────────────────
        $score += match(true) {
            $position === 1 => 30,
            $position === 2 => 25,
            $position === 3 => 20,
            $position <= 5  => 15,
            $position <= 10 => 10,
            default         => 5,
        };

        // ── FRESHNESS — tính từ iso_date (giờ đã qua) ────────────────────────
        $h = $this->hoursAgo($article['date'] ?? '');
        $score += match(true) {
            $h <= 1  => 50,
            $h <= 3  => 40,
            $h <= 6  => 35,
            $h <= 12 => 25,
            $h <= 24 => 10,
            default  => 0,
        };

        // ── TRUSTED SOURCE — domain khớp news_webs của category ─────────────
        foreach ($trusted as $source) {
            $srcDomain = is_array($source) ? ($source['domain'] ?? '') : $source;
            if (str_contains($domain, $srcDomain)) {
                $score += 20;
                break;
            }
        }

        return max(0, $score);
    }

    private function hoursAgo(string $date): float
    {
        if (empty($date)) return 999.0;
        $parsed = strtotime($date);
        return $parsed !== false ? (time() - $parsed) / 3600 : 999.0;
    }

    // ── FB VIRALITY SCORE — riêng cho Facebook, độc lập với quality score ────

    private function calcFbScore(array $article): int
    {
        $score   = 0;
        $title   = strtolower($article['title'] ?? '');
        $snippet = $article['snippet'] ?? '';
        $stories = count($article['stories'] ?? []);

        // ── FRESHNESS — FB algorithm ưu tiên nội dung mới ────────────────────
        $h = $this->hoursAgo($article['date'] ?? '');
        $score += match(true) {
            $h <= 1  => 40,
            $h <= 3  => 35,
            $h <= 6  => 28,
            $h <= 12 => 15,
            $h <= 24 => 5,
            default  => 0,
        };

        // ── TOP STORY — Google đã xác nhận viral ─────────────────────────────
        if (!empty($article['stories'])) {
            $score += 20 + min($stories * 3, 25); // +20 base, +3/story, max +45
        }

        // ── BREAKING / HARD NEWS (+40) — trigger share cao nhất ──────────────
        if (preg_match('/\b(breaking|just in|confirmed|official|signs|signed|traded|trade|fired|cut|released|suspended|arrested|injured|injury|surgery|retires|retirement|dies|death|charged|indicted|accused|collapses|resigns)\b/', $title)) {
            $score += 40;
        }

        // ── CONTROVERSY / DRAMA (+35) — comment & share magnet ───────────────
        if (preg_match('/\b(slams|blasts|rips|calls out|responds|fires back|feud|beef|outrage|backlash|furious|angry|upset|betrayal|walks out|demands|controversy|drama|heated|shocking|accuses)\b/', $title)) {
            $score += 35;
        }

        // ── EMOTIONAL / HUMAN INTEREST (+25) ─────────────────────────────────
        if (preg_match('/\b(heartbreaking|emotional|tears|crying|incredible|amazing|unbelievable|insane|wild|legend|hero|tribute|honors|remembers|reveals|opens up|breaks silence|comeback|miracle|devastating)\b/', $title)) {
            $score += 25;
        }

        // ── FAN LOYALTY / RIVALRY (+20) — fan base tranh cãi trong comments ──
        if (preg_match('/\b(vs\.?|beats|defeats|crushes|destroys|embarrasses|dominates|rivalry|rivals?|enemy|greatest|worst|disrespects?|snubs?)\b/', $title)) {
            $score += 20;
        }

        // ── RECORD / FIRST / HISTORIC (+15) — "chưa từng thấy" = shareable ──
        if (preg_match('/\b(first time|first ever|never before|record|historic|all.time|milestone|first in \d+)\b/', $title)) {
            $score += 15;
        }

        // ── MONEY / CONTRACT / NUMBER (+10) — cụ thể → credible → shareable ─
        if (preg_match('/\$[\d,]+[mk]?|\b\d+[\s-]year\b|\b\d+[\s-]million\b|\b#\s*1\b/', $title)) {
            $score += 10;
        }

        // ── SNIPPET DÀI (+5) ─────────────────────────────────────────────────
        if (strlen($snippet) > 100) $score += 5;

        // ── PENALTIES ─────────────────────────────────────────────────────────
        // Analysis/listicle — không ai share trên FB
        if (preg_match('/\b(mock draft|seven.round|trade proposal|could fit|how .+ could|what to know|everything you need|breakdown|film study|deep dive|explainer|roundup|recap|power rankings|ranking|grades|report card|fantasy)\b/', $title)) {
            $score -= 35;
        }

        // Evergreen — FB không boost nội dung cũ
        if (preg_match('/\b(history of|look back|throwback|greatest ever|best ever|legacy|in \d{4}|since \d{4}|back in \d{4})\b/', $title)) {
            $score -= 25;
        }

        // Rumor/prediction — ít urgent hơn hard news
        if (preg_match('/\b(could|might|projected|expected|reportedly|rumored|sources say|per report)\b/', $title)) {
            $score -= 10;
        }

        return max(0, $score);
    }

    private function isVideoContent(string $title, string $url): bool
    {
        // Title pattern: "Some Title | 'Show Name'" hoặc "Title | Show Name" → video/podcast
        if (preg_match("/\|\s*['\"]?.{3,40}['\"]?\s*$/u", $title)) {
            return true;
        }

        // URL chứa /video/, /watch/, /episode/
        if (preg_match('#/(video|watch|episode|podcast|stream|live)s?/#i', $url)) {
            return true;
        }

        // Video platforms
        $videoDomains = ['youtube.com', 'youtu.be', 'vimeo.com', 'twitch.tv', 'tiktok.com'];
        $domain = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
        foreach ($videoDomains as $vd) {
            if (str_contains($domain, $vd)) return true;
        }

        // Title keywords chỉ video/podcast
        $titleLower = strtolower($title);
        if (preg_match('/\b(watch:|listen:|podcast:|episode \d+|ep\.\s*\d+|full video|highlights?:)\b/i', $titleLower)) {
            return true;
        }

        return false;
    }

    private function scoreByDate(string $date): int
    {
        // Format: "04/02/2026, 07:47 PM, +0000 UTC"
        $cleaned = trim(preg_replace('/,?\s*\+\d{4}\s*UTC$/i', '', $date));
        $parsed  = strtotime($cleaned);

        if ($parsed === false) return 5; // không parse được → cho điểm nhỏ

        $hours = (time() - $parsed) / 3600;

        return match(true) {
            $hours <= 1  => 30,
            $hours <= 6  => 20,
            $hours <= 12 => 15,
            $hours <= 24 => 10,
            default      => 0,
        };
    }

    private function isWithinHours(string $date, int $hours): bool
    {
        if (empty($date)) return $hours >= 24;

        $d        = strtolower(trim($date));
        $limitSec = $hours * 3600;

        // Relative strings (fallback — ít gặp sau khi dùng iso_date)
        if (str_contains($d, 'minute')) return true;
        if (preg_match('/^(\d+)\s*h(?:our)?s?\b/', $d, $m)) return (int)$m[1] <= $hours;
        if (preg_match('/^1 day/', $d)) return $hours >= 24;
        if (preg_match('/^(\d+) day/', $d, $m) && (int)$m[1] > 1) return false;

        // ISO 8601 hoặc absolute date — strtotime xử lý trực tiếp
        $parsed = strtotime($date);
        if ($parsed === false) return true;

        return (time() - $parsed) <= $limitSec;
    }
}
