<?php
// app/Services/Admin/SerpApiService.php

namespace App\Services\Admin;

use App\Models\NewsSource;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SerpApiService
{
    protected Client $client;
    protected string $apiKey;

    // Loaded from news_sources table (cached 1 hour)
    protected function trustedSources(): array
    {
        return NewsSource::trustedDomains();
    }

    protected function blockedSources(): array
    {
        return NewsSource::blockedDomains();
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
                                'date'        => (!empty($story['date']) ? $story['date'] : null) ?? $item['date'] ?? '',
                                'position'    => $item['position'] ?? 99,   // giữ position của cluster
                                'thumbnail'   => $story['thumbnail']        ?? $item['thumbnail'] ?? '',
                                'stories'     => $stories,                  // toàn bộ cluster để scoring
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
                            'date'        => $item['date']           ?? '',
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

    public function filterRecent(array $articles, int $limit = 20): array
    {
        $blocked = $this->blockedSources();
        $trusted = $this->trustedSources();
        $seen    = [];
        $result  = [];

        foreach ($articles as $article) {
            $link  = $article['link'] ?? '';
            $title = $article['title'] ?? '';
            if (empty($link) || empty($title)) continue;
            if (isset($seen[$link])) continue;
            $seen[$link] = true;

            $domain    = strtolower(parse_url($link, PHP_URL_HOST) ?? '');
            $isBlocked = false;
            foreach ($blocked as $b) {
                if (str_contains($domain, $b)) { $isBlocked = true; break; }
            }
            if ($isBlocked) continue;
            if ($this->isVideoContent($title, $link)) continue;

            $score   = $this->calcScore($article, $domain, $trusted);
            $fbScore = $this->calcFbScore($article);

            $result[] = array_merge($article, [
                'quality_score' => $score,
                'fb_score'      => $fbScore,
                'domain'        => $domain,
            ]);
        }

        // Sort by parsed date: newest first
        usort($result, fn($a, $b) =>
            $this->parseDateToTimestamp($b['date'] ?? '') <=> $this->parseDateToTimestamp($a['date'] ?? '')
        );

        return array_values(array_slice($result, 0, $limit));
    }

    private function parseDateToTimestamp(string $date): int
    {
        if (empty($date)) return 0;
        $d = strtolower(trim($date));

        if (str_contains($d, 'minute')) return time() - 30 * 60;
        if (preg_match('/^(\d+)\s+hour/', $d, $m)) return time() - (int)$m[1] * 3600;
        if (preg_match('/^1 day/', $d)) return time() - 86400;
        if (preg_match('/^(\d+) day/', $d, $m)) return time() - (int)$m[1] * 86400;

        $cleaned = trim(preg_replace('/,?\s*\+\d{4}\s*UTC$/i', '', $date));
        $parsed  = strtotime($cleaned);
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

    public function filterAndScore(array $articles): array
    {
        // Load 1 lần — tránh cache read lặp lại khi doFilterAndScore chạy 2 lần
        $trusted = $this->trustedSources();
        $blocked = $this->blockedSources();

        $result = $this->doFilterAndScore($articles, 12, $trusted, $blocked);

        if (count($result) < 10) {
            Log::info(sprintf('[FilterAndScore] Only %d in 12h, expanding to 24h', count($result)));
            $result = $this->doFilterAndScore($articles, 24, $trusted, $blocked);
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

            // cluster cũ hơn 12h không còn hot
            $maxHours = $topStory ? 12 : $hours;
            if (!$this->isWithinHours($date, $maxHours)) continue;

            $domain = strtolower(parse_url($link, PHP_URL_HOST) ?? '');
            foreach ($blocked as $b) {
                if (str_contains($domain, $b)) continue 2;
            }

            if ($this->isVideoContent($title, $link)) continue;

            $score   = $this->calcScore($article, $domain, $trusted);
            $fbScore = $this->calcFbScore($article);

            if ($score < 20) continue;

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

    private function calcScore(array $article, string $domain, array $trusted = []): int
    {
        $score    = 0;
        $position = (int)($article['position'] ?? 99);
        $date     = strtolower($article['date'] ?? '');
        $stories  = count($article['stories'] ?? []);
        $topStory = !empty($article['stories']);

        // ── CLUSTER SIZE — tín hiệu mạnh nhất của Google News ────────────────
        // Google gom nhiều nguồn = đã xác nhận sự kiện có thật và đang trending
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

        // ── FRESHNESS — yếu tố quan trọng nhất trong Google News ─────────────
        $score += match(true) {
            str_contains($date, 'minute')           => 50,
            str_contains($date, '1 hour')           => 40,
            preg_match('/^[23]\s*h/', $date) === 1  => 35,
            str_contains($date, 'hour')             => 25,  // 4-23h
            str_contains($date, '1 day')            => 10,
            default                                 => $this->scoreByDate($date),
        };

        // ── TRUSTED SOURCE — publisher authority từ news_sources table ────────
        foreach ($trusted as $trustedDomain) {
            if (str_contains($domain, $trustedDomain)) {
                $score += 20;
                break;
            }
        }

        return max(0, $score);
    }

    // ── FB VIRALITY SCORE — riêng cho Facebook, độc lập với quality score ────

    private function calcFbScore(array $article): int
    {
        $score   = 0;
        $title   = strtolower($article['title'] ?? '');
        $snippet = $article['snippet'] ?? '';
        $date    = strtolower($article['date'] ?? '');
        $stories = count($article['stories'] ?? []);

        // ── FRESHNESS — FB algorithm ưu tiên nội dung mới ────────────────────
        $score += match(true) {
            str_contains($date, 'minute')           => 40,
            str_contains($date, '1 hour')           => 35,
            preg_match('/^[23] hour/', $date) === 1 => 28,
            str_contains($date, 'hour')             => 15,  // 4-23h
            str_contains($date, '1 day')            => 5,
            default                                 => 0,
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
        // Empty date → không biết ngày đăng → chỉ cho qua nếu không phải top_story strict mode
        if (empty($date)) return $hours >= 24;

        $d        = strtolower(trim($date));
        $limitSec = $hours * 3600;

        if (str_contains($d, 'minute')) return true;

        // "11h ago" (SerpAPI viết tắt) hoặc "11 hours ago"
        if (preg_match('/^(\d+)\s*h(?:our)?s?\b/', $d, $m)) {
            return (int)$m[1] <= $hours;
        }

        // "1 day ago" = 24h
        if (preg_match('/^1 day/', $d)) return $hours >= 24;

        // "2+ days ago" → luôn quá hạn
        if (preg_match('/^(\d+) day/', $d, $m) && (int)$m[1] > 1) return false;

        // ISO hoặc absolute date
        $cleaned = trim(preg_replace('/,?\s*\+\d{4}\s*UTC$/i', '', $date));
        $parsed  = strtotime($cleaned);

        if ($parsed === false) return true;

        return (time() - $parsed) <= $limitSec;
    }
}
