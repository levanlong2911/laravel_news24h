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

            // Score đầy đủ — không có trend nhưng vẫn tính freshness/source/signals
            $score = $this->calcScore($article, $domain, [], $trusted);

            $result[] = array_merge($article, [
                'quality_score' => $score,
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

    public function filterAndScore(array $articles, array $trend = []): array
    {
        $minScore = empty($trend) ? 20 : 30;

        // Lần 1: lấy bài trong 12h
        $result = $this->doFilterAndScore($articles, $trend, $minScore, 12);

        // Nếu thiếu 10 bài → mở rộng lên 24h
        if (count($result) < 10) {
            Log::info(sprintf(
                '[FilterAndScore] Only %d articles in 12h, expanding to 24h',
                count($result)
            ));
            $result = $this->doFilterAndScore($articles, $trend, $minScore, 24);
        }

        Log::info(sprintf('[FilterAndScore] Final: %d articles', count($result)));
        return $result;
    }

    private function doFilterAndScore(array $articles, array $trend, int $minScore, int $hours): array
    {
        // Load 1 lần — tránh gọi Cache::remember lặp lại trong loop
        $trusted = $this->trustedSources();
        $blocked = $this->blockedSources();

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

            // top_story luôn giới hạn 12h — sự kiện cluster cũ hơn 12h không còn hot
            $maxHours = $topStory ? 12 : $hours;
            if (!$this->isWithinHours($date, $maxHours)) continue;

            $domain = strtolower(parse_url($link, PHP_URL_HOST) ?? '');

            foreach ($blocked as $b) {
                if (str_contains($domain, $b)) continue 2;
            }

            // Lọc bài dạng video/podcast — không crawl được nội dung text
            if ($this->isVideoContent($title, $link)) continue;

            $score = $this->calcScore($article, $domain, $trend, $trusted);

            if ($score < $minScore) continue;

            $scored[] = array_merge($article, [
                'quality_score' => $score,
                'domain'        => $domain,
            ]);
        }

        usort($scored, fn($a, $b) => $b['quality_score'] <=> $a['quality_score']);

        return array_values($scored);
    }

    // ══════════════════════════════════════════════
    // HELPERS
    // ══════════════════════════════════════════════

    private function calcScore(array $article, string $domain, array $trend, array $trusted = []): int
    {
        $score = 0;

        // Trend signals
        $traffic  = (int)($trend['traffic'] ?? 0);
        $increase = (int)($trend['increase'] ?? 0);
        $active   = (bool)($trend['active'] ?? false);

        $score += match(true) {
            $traffic >= 1_000_000 => 40,
            $traffic >= 500_000   => 30,
            $traffic >= 100_000   => 20,
            $traffic >= 10_000    => 10,
            default               => 0,
        };

        $score += match(true) {
            $increase >= 1000 => 30,
            $increase >= 500  => 15,
            $increase >= 100  => 5,
            default           => 0,
        };

        if ($active) $score += 20;

        // News signals
        $position  = (int)($article['position'] ?? 99);
        $date      = strtolower($article['date'] ?? '');
        $stories   = count($article['stories'] ?? []);
        $snippet   = $article['snippet'] ?? '';
        $title     = $article['title'] ?? '';
        $topStory  = !empty($article['stories']); // Google gom nhiều nguồn = viral

        // TOP STORY — signal viral mạnh nhất: Google đã cluster nhiều nguồn lại
        if ($topStory) {
            $score += match(true) {
                $stories >= 10 => 50,
                $stories >= 5  => 35,
                $stories >= 2  => 20,
                default        => 10,
            };
        }

        // Position trong Google News (Google's own ranking signal)
        $score += max(0, (10 - min($position, 10)) * 3); // max +27 (giảm từ *5 xuống *3)

        // Freshness — TĂNG TRỌNG SỐ: bài mới là yếu tố quan trọng nhất
        $score += match(true) {
            str_contains($date, 'minute') => 50,  // < 1h: cực kỳ fresh
            str_contains($date, '1 hour') => 40,
            str_contains($date, 'hour')   => 30,  // 2-23h
            str_contains($date, '1 day')  => 10,
            default                       => $this->scoreByDate($date),
        };

        // Trusted source
        foreach ($trusted as $trustedDomain) {
            if (str_contains($domain, $trustedDomain)) {
                $score += 20; // giảm từ 30 → 20, source không quan trọng bằng freshness
                break;
            }
        }

        $titleLower = strtolower($title);

        // ── BREAKING NEWS signals (+25) ───────────────────────────────────────
        // Sự kiện thực tế: trade, injury, signing, firing → viral cao nhất FB
        if (preg_match('/\b(breaking|just in|confirmed|official|signs|signed|traded|trade|fired|released|suspended|arrested|injured|injury|surgery|retires|retirement|dies|death|charged|indicted|accused)\b/', $titleLower)) {
            $score += 25;
        }

        // ── CONTROVERSY / DRAMA signals (+20) ────────────────────────────────
        // Người dùng FB chia sẻ nhiều nhất khi có drama, tranh cãi
        if (preg_match('/\b(controversy|drama|feud|beef|slams|blasts|rips|calls out|responds|fires back|shocking|outrage|backlash|criticism|frustrated|furious|angry|upset|betrayal|quit|walks out|demands)\b/', $titleLower)) {
            $score += 20;
        }

        // ── EMOTION / HUMAN INTEREST signals (+15) ───────────────────────────
        if (preg_match('/\b(heartbreaking|emotional|tears|crying|incredible|amazing|unbelievable|insane|wild|crazy|legend|hero|tribute|honors|remembers|reveals|opens up|breaks silence)\b/', $titleLower)) {
            $score += 15;
        }

        // ── Số trong tiêu đề → CTR cao hơn (+5) ─────────────────────────────
        if (preg_match('/\d+/', $title)) {
            $score += 5;
        }

        if (strlen($snippet) > 120) $score += 5;

        // ── PENALIZE analysis/listicle — không viral trên FB ─────────────────
        if (preg_match('/\b(mock draft|seven.round|trade proposal|could fit|how .+ could|what to know|everything you need|breakdown|film study|deep dive|explainer|roundup|recap|headlines:|power rankings|ranking|grades|report card|fantasy)\b/', $titleLower)) {
            $score -= 25;
        }

        // ── PENALIZE evergreen/historical content ─────────────────────────────
        if (preg_match('/\b(history of|look back|throwback|all.time|greatest ever|best ever|legacy|how .+ landed|in \d{4}|since \d{4}|back in \d{4})\b/', $titleLower)) {
            $score -= 30;
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

        // Relative string: "X minutes ago" → luôn trong 1h → pass mọi threshold
        if (str_contains($d, 'minute')) return true;

        // "X hours ago"
        if (preg_match('/^(\d+)\s+hour/', $d, $m)) {
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
