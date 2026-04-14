<?php
// app/Services/Admin/TrendingNewsService.php

namespace App\Services\Admin;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TrendingNewsService
{
    protected Client $client;

    // Nguồn uy tín
    protected array $trusted = [
        'espn.com', 'nfl.com', 'nba.com', 'bbc.com', 'bbc.co.uk',
        'reuters.com', 'apnews.com', 'cnn.com', 'theguardian.com',
        'nbcsports.com', 'foxnews.com', 'nbcnews.com', 'cbsnews.com',
        'usatoday.com', 'washingtonpost.com', 'nytimes.com',
        'sky.com', 'independent.co.uk', 'telegraph.co.uk',
    ];

    // Nguồn bị block
    protected array $blocked = [
        'reddit.com', 'quora.com', 'medium.com', 'blogspot.com',
        'pinterest.com', 'tumblr.com', 'yahoo.com', 'msn.com',
    ];

    public function __construct()
    {
        $this->client = new Client([
            'timeout'         => 15,
            'connect_timeout' => 5,
            'headers'         => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'Accept'     => 'application/json, text/html',
            ],
        ]);
    }

    // ── Pipeline chính ───────────────────────────────
    public function getTrending24h(string $geo = 'US', int $limit = 20): array
    {
        // 1. Lấy trending topics
        $topics = $this->getGoogleTrends($geo);

        if (empty($topics)) {
            Log::warning('TrendingNewsService: không lấy được Google Trends');
            return [];
        }

        // 2. Tìm bài từng topic qua SerpAPI
        $allArticles = [];

        foreach (array_slice($topics, 0, 8) as $topic) {
            $articles = $this->searchNews($topic['keyword']);

            foreach ($articles as $article) {
                $article['trend_traffic'] = $topic['traffic'];
                $article['trend_keyword'] = $topic['keyword'];
                $article['trend_raw']     = $topic['raw'];
                $allArticles[]            = $article;
            }

            usleep(300_000); // 0.3s tránh rate limit SerpAPI
        }

        if (empty($allArticles)) {
            return [];
        }

        // 3. Filter 24h + score + sort
        $filtered = $this->filterAndScore($allArticles);

        return array_slice($filtered, 0, $limit);
    }

    // ── Google Trends ────────────────────────────────
    public function getGoogleTrends(string $geo = 'US'): array
    {
        $cacheKey = "google_trends_{$geo}";

        return Cache::remember($cacheKey, 3600, function () use ($geo) {
            try {
                $response = $this->client->get('https://trends.google.com/trends/api/dailytrends', [
                    'query' => [
                        'hl'  => 'en-US',
                        'tz'  => '-60',
                        'geo' => $geo,
                        'ns'  => 15,
                    ]
                ]);

                $body = (string) $response->getBody();
                dd($body);

                // Google Trends prefix cần bỏ: )]}',\n
                $body = preg_replace('/^\)\]\}\'[,\n]*/', '', $body);
                $data = json_decode($body, true);

                $topics      = [];
                $trendingDay = $data['default']['trendingSearchesDays'][0] ?? [];

                foreach ($trendingDay['trendingSearches'] ?? [] as $trend) {
                    $keyword = $trend['title']['query'] ?? '';
                    $traffic = $trend['formattedTraffic'] ?? '0';

                    if (empty($keyword)) continue;

                    // Bỏ keyword quá ngắn hoặc không rõ nghĩa
                    if (str_word_count($keyword) < 1 || strlen($keyword) < 3) continue;

                    $topics[] = [
                        'keyword' => $keyword,
                        'traffic' => $this->parseTraffic($traffic),
                        'raw'     => $traffic,
                    ];
                }

                // Sort traffic cao nhất
                usort($topics, fn($a, $b) => $b['traffic'] <=> $a['traffic']);

                Log::info('Google Trends: lấy được ' . count($topics) . ' topics', [
                    'geo' => $geo
                ]);



                return $topics;

            } catch (\Exception $e) {
                dd($e->getMessage());
                Log::warning('Google Trends error: ' . $e->getMessage());
                return [];
            }
        });
    }

    // ── SerpAPI Google News ──────────────────────────
    public function searchNews(string $keyword, int $limit = 10): array
    {
        $apiKey = config('services.serpapi.key');

        if (empty($apiKey)) {
            Log::error('SerpAPI key chưa cấu hình trong services.php');
            return [];
        }

        try {
            $response = $this->client->get('https://serpapi.com/search', [
                'query' => [
                    'engine'  => 'google_news',
                    'q'       => $keyword,
                    'gl'      => 'us',
                    'hl'      => 'en',
                    'num'     => $limit,
                    'api_key' => $apiKey,
                ]
            ]);

            $data    = json_decode($response->getBody(), true);
            $results = $data['news_results'] ?? [];

            return array_map(fn($item) => [
                'title'     => $item['title'] ?? '',
                'link'      => $item['link'] ?? '',
                'snippet'   => $item['snippet'] ?? '',
                'source'    => $item['source']['name'] ?? '',
                'date'      => $item['date'] ?? '',
                'position'  => $item['position'] ?? 99,
                'thumbnail' => $item['thumbnail'] ?? '',
                'top_story' => !empty($item['stories']),
            ], $results);

        } catch (RequestException $e) {
            Log::warning("SerpAPI error [{$keyword}]: " . $e->getMessage());
            return [];
        }
    }

    // ── Filter + Score ───────────────────────────────
    private function filterAndScore(array $articles): array
    {
        $scored = [];
        $seen   = [];

        foreach ($articles as $article) {
            $link  = $article['link'] ?? '';
            $title = $article['title'] ?? '';
            $date  = $article['date'] ?? '';

            if (empty($link) || empty($title)) continue;

            // Bỏ duplicate URL
            if (isset($seen[$link])) continue;
            $seen[$link] = true;

            // Chỉ lấy bài trong 24h
            if (!$this->isWithin24h($date)) continue;

            $domain = strtolower(parse_url($link, PHP_URL_HOST) ?? '');

            // Bỏ nguồn block
            foreach ($this->blocked as $b) {
                if (str_contains($domain, $b)) continue 2;
            }

            $score = $this->calcScore($article, $domain);

            if ($score < 20) continue; // ngưỡng tối thiểu

            $scored[] = array_merge($article, [
                'quality_score' => $score,
                'domain'        => $domain,
            ]);
        }

        usort($scored, fn($a, $b) => $b['quality_score'] <=> $a['quality_score']);

        return array_values($scored);
    }

    private function calcScore(array $article, string $domain): int
    {
        $score = 0;

        // Nguồn uy tín: +50
        foreach ($this->trusted as $t) {
            if (str_contains($domain, $t)) {
                $score += 50;
                break;
            }
        }

        // Position Google (1=best): tối đa +45
        $position = (int) ($article['position'] ?? 99);
        $score   += max(0, (10 - min($position, 10)) * 5);

        // Top story: +30
        if (!empty($article['top_story'])) $score += 30;

        // Traffic: tối đa +40
        $traffic = (int) ($article['trend_traffic'] ?? 0);
        $score  += match(true) {
            $traffic >= 1_000_000 => 40,
            $traffic >= 500_000   => 30,
            $traffic >= 100_000   => 20,
            $traffic >= 10_000    => 10,
            default               => 0,
        };

        // Title dài hợp lý: +10
        if (str_word_count($article['title'] ?? '') >= 6) $score += 10;

        // Snippet có nội dung: +5
        if (strlen($article['snippet'] ?? '') > 80) $score += 5;

        // Trừ clickbait: -20
        $clickbait = ['shocking', "you won't believe", 'mind-blowing', 'omg', '!!!'];
        foreach ($clickbait as $c) {
            if (str_contains(strtolower($article['title'] ?? ''), $c)) {
                $score -= 20;
                break;
            }
        }

        return $score;
    }

    // ── Helpers ──────────────────────────────────────
    private function isWithin24h(string $date): bool
    {
        if (empty($date)) return false;

        $d = strtolower(trim($date));

        if (str_contains($d, 'minute') || str_contains($d, 'hour')) return true;
        if (preg_match('/^1 day/', $d)) return true;
        if (preg_match('/^(\d+) day/', $d, $m) && (int) $m[1] > 1) return false;

        try {
            $parsed = strtotime($date);
            return $parsed !== false && (time() - $parsed) <= 86400;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function parseTraffic(string $raw): int
    {
        $raw = strtoupper(str_replace(['+', ',', ' '], '', $raw));
        if (str_contains($raw, 'M')) return (int) (floatval($raw) * 1_000_000);
        if (str_contains($raw, 'K')) return (int) (floatval($raw) * 1_000);
        return (int) $raw;
    }
}
