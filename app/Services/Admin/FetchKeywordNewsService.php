<?php

namespace App\Services\Admin;

use App\Models\Keyword;
use App\Models\RawArticle;
use App\Services\ViralScoreService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FetchKeywordNewsService
{
    public function __construct(
        private SerpApiService    $serpApi,
        private ViralScoreService $viralScore,
    ) {}

    public function fetch(Keyword $kw): array
    {
        Log::info("[FetchNews] Start: {$kw->name}");

        $mainQuery = !empty($kw->search_keyword) ? $kw->search_keyword : ($kw->name . ' news');
        $queries   = array_values(array_unique(array_filter(
            array_merge([$mainQuery], $kw->extra_queries ?? [])
        )));

        $allRaw = [];
        $seen   = [];

        foreach ($queries as $query) {
            foreach ($this->serpApi->searchNews($query) as $item) {
                $link = $item['link'] ?? '';
                if ($link && !isset($seen[$link])) {
                    $seen[$link] = true;
                    $allRaw[]    = $item;
                }
            }
        }

        Log::info("[FetchNews] Queries: " . count($queries) . " → {$mainQuery} | Total raw: " . count($allRaw));

        if (empty($allRaw)) {
            Log::warning("[FetchNews] No results: {$kw->name}");
            return ['saved' => 0, 'top' => 0, 'recent' => 0];
        }
        $scored = $this->serpApi->filterAndScore($allRaw);

        // ViralScore + quality_score map cho tất cả filtered articles
        $viralScores     = [];
        $qualityScoreMap = [];
        foreach ($scored as $item) {
            $url = $item['link'] ?? '';
            if ($url) {
                $viralScores[$url]     = $this->viralScore->calculateFromRaw($item, $kw)['score'];
                $qualityScoreMap[$url] = (int) ($item['quality_score'] ?? 0);
            }
        }

        // Entity Heat Map — boost articles where entity dominates the news cycle
        $entityCount = [];
        foreach ($allRaw as $article) {
            preg_match_all('/\b[A-Z][a-z]+(?:\s+[A-Z][a-z]+)+\b/', $article['title'] ?? '', $m);
            foreach ($m[0] as $entity) {
                $entityCount[$entity] = ($entityCount[$entity] ?? 0) + 1;
            }
        }
        $hotEntities = array_filter($entityCount, fn($c) => $c >= 3);

        if (!empty($hotEntities)) {
            $hotLower = [];
            foreach ($hotEntities as $entity => $count) {
                $hotLower[strtolower($entity)] = $count;
            }

            foreach ($scored as $item) {
                $url = $item['link'] ?? '';
                if (!$url) continue;
                $titleLower = strtolower($item['title'] ?? '');
                $maxBoost   = 0;
                foreach ($hotLower as $entity => $count) {
                    if (str_contains($titleLower, $entity)) {
                        $maxBoost = max($maxBoost, match(true) {
                            $count >= 6 => 15,
                            $count >= 4 => 10,
                            default     => 5,
                        });
                    }
                }
                if ($maxBoost > 0) {
                    $viralScores[$url] = min(100, ($viralScores[$url] ?? 0) + $maxBoost);
                }
            }

            Log::info('[EntityHeat] ' . $kw->name . ': ' . implode(', ', array_map(
                fn($e, $c) => "{$e}({$c})", array_keys($hotEntities), $hotEntities
            )));
        }

        $topics = !empty($scored) ? $this->buildTopics($scored, $viralScores) : [];
        usort($topics, fn($a, $b) => $b['topic_score'] <=> $a['topic_score']);

        $top10 = [];
        foreach (array_slice($topics, 0, 10) as $topic) {
            $article                  = $topic['best_article'];
            $article['_topic_score']  = (int) $topic['topic_score'];
            $top10[]                  = $article;
        }

        // Fill remaining slots with best fb_score articles not already selected
        if (count($top10) < 10) {
            $selectedUrls = array_flip(array_filter(array_column($top10, 'link')));
            $candidates   = array_filter($scored, fn($a) => !isset($selectedUrls[$a['link'] ?? '']));
            usort($candidates, fn($a, $b) => ($viralScores[$b['link'] ?? ''] ?? 0) <=> ($viralScores[$a['link'] ?? ''] ?? 0));
            foreach (array_slice($candidates, 0, 10 - count($top10)) as $filler) {
                $filler['_topic_score'] = 0;
                $top10[]                = $filler;
            }
        }

        if (empty($top10)) {
            Log::warning("[FetchNews] All filtered out: {$kw->name}");
        }

        $recent50 = $this->serpApi->filterRecent($allRaw, 50, $kw->category_id ?? '');

        if (empty($top10) && empty($recent50)) {
            Log::warning("[FetchNews] Nothing to save: {$kw->name}");
            return ['saved' => 0, 'top' => 0, 'recent' => 0];
        }

        // Merge, dedup by URL (top > recent)
        $byUrl = [];
        foreach ($top10 as $item) {
            $url = $item['link'] ?? '';
            if ($url) $byUrl[$url] = array_merge($item, ['list_type' => 'top']);
        }
        foreach ($recent50 as $item) {
            $url = $item['link'] ?? '';
            if ($url && !isset($byUrl[$url])) {
                $byUrl[$url] = array_merge($item, ['list_type' => 'recent']);
            }
        }

        // Score items chưa được score (recent articles ngoài 12-24h window của filterAndScore)
        foreach ($byUrl as $url => $item) {
            if (!isset($viralScores[$url])) {
                $viralScores[$url] = $this->viralScore->calculateFromRaw($item, $kw)['score'];
            }
            if (!isset($qualityScoreMap[$url])) {
                $qualityScoreMap[$url] = $this->serpApi->scoreArticle($item)['quality_score'] ?? 0;
            }
        }

        // Pre-compute url_hash để tránh md5 gọi 2 lần
        $urlHashes = [];
        foreach ($byUrl as $url => $_) {
            $urlHashes[$url] = md5($url);
        }

        $existing = RawArticle::whereIn('url_hash', array_values($urlHashes))
            ->pluck('url_hash')->flip()->all();

        $rows      = [];
        $now       = now();
        $expiresAt = $now->copy()->addHours(24); // tính 1 lần ngoài loop

        foreach ($byUrl as $url => $item) {
            $urlHash = $urlHashes[$url];
            if (isset($existing[$urlHash])) continue;

            $rows[] = [
                'id'             => Str::uuid()->toString(),
                'keyword_id'     => $kw->id,
                'title'          => $item['title'] ?? '',
                'url'            => $url,
                'url_hash'       => $urlHash,
                'snippet'        => $item['snippet'] ?? '',
                'source'         => $item['source'] ?? '',
                'source_icon'    => $item['source_icon'] ?? '',
                'thumbnail'      => $item['thumbnail'] ?? '',
                'viral_score'    => $qualityScoreMap[$url] ?? 0,
                'topic_score'    => $item['_topic_score'] ?? 0,
                'fb_score'       => $viralScores[$url] ?? 0,
                'position'       => (int) ($item['position'] ?? 0),
                'published_date' => $item['date'] ?? '',
                'stories_count'  => count($item['stories'] ?? []),
                'top_story'      => !empty($item['stories']),
                'list_type'      => $item['list_type'],
                'status'         => 'pending',
                'expires_at'     => $expiresAt,
                'created_at'     => $now,
                'updated_at'     => $now,
            ];
        }

        $saved = count($rows);
        if ($saved > 0) {
            RawArticle::insert($rows);
        }

        Log::info("[FetchNews] Saved {$saved} for: {$kw->name} (top=" . count($top10) . " recent=" . count($recent50) . ")");

        return ['saved' => $saved, 'top' => count($top10), 'recent' => count($recent50)];
    }

    private function buildTopics(array $articles, array $viralScores): array
    {
        // 1. Cluster — lưu thêm fps[] để tính cohesion sau
        $clusters = [];
        foreach ($articles as $article) {
            $fp      = $this->titleFingerprint($article['title'] ?? '');
            $matched = false;
            foreach ($clusters as &$cluster) {
                if ($this->jaccardSimilarity($fp, $cluster['fp']) >= 0.45) {
                    $cluster['articles'][] = $article;
                    $cluster['fps'][]      = $fp;
                    $matched = true;
                    break;
                }
            }
            unset($cluster);
            if (!$matched) {
                $clusters[] = ['fp' => $fp, 'fps' => [$fp], 'articles' => [$article]];
            }
        }

        // 2. Score từng topic — single pass per cluster
        $now    = time();
        $topics = [];

        foreach ($clusters as $cluster) {
            $arts         = $cluster['articles'];
            $storiesCount = max(array_map(fn($a) => count($a['stories'] ?? []), $arts));
            $articleCount = count($arts);

            // Single pass: uniqueSources, avgViral, freshScore, topStory, article hours
            $sources      = [];
            $viralSum     = 0.0;
            $freshSum     = 0.0;
            $hasTopStory  = false;
            $articleHours = [];

            foreach ($arts as $i => $a) {
                $url = $a['link'] ?? '';
                if ($a['source'] ?? '') $sources[$a['source']] = true;
                $viralSum        += $viralScores[$url] ?? 0;
                $ts               = !empty($a['date']) ? (strtotime($a['date']) ?: $now) : $now;
                $h                = ($now - $ts) / 3600;
                $articleHours[$i] = $h;
                $freshSum        += match(true) {
                    $h <= 3  => 10,
                    $h <= 6  => 7,
                    $h <= 12 => 4,
                    default  => 1,
                };
                if (!empty($a['top_story'])) $hasTopStory = true;
            }

            $uniqueSources = count($sources);
            $avgViral      = $viralSum / $articleCount;
            $freshScore    = $freshSum / $articleCount;

            // Early trend: breaking news 1 story nhưng cực hot + cực mới (< 3h)
            $isEarlyTrend = ($storiesCount === 1 && $avgViral > 85 && $freshScore >= 10 && $uniqueSources >= 1);

            // Loại topic rác — nhưng cho phép early trend qua
            if (!$isEarlyTrend && ($storiesCount < 2 || $uniqueSources < 2)) continue;

            $topStoryBoost = $hasTopStory ? 10 : 0;

            $titleText   = strtolower(implode(' ', array_column($arts, 'title')));
            $entityBoost = $this->entityBoost($titleText);

            $saturationPenalty = match(true) {
                $storiesCount > 25 => 10,
                $storiesCount > 15 => 5,
                default            => 0,
            };

            // Cohesion penalty — pairwise avg Jaccard (chỉ tính khi ≥3 bài)
            $cohesionPenalty = 0;
            if ($articleCount >= 3) {
                $fps   = $cluster['fps'];
                $n     = count($fps);
                $sum   = 0.0;
                $pairs = 0;
                for ($i = 0; $i < $n; $i++) {
                    for ($j = $i + 1; $j < $n; $j++) {
                        $sum += $this->jaccardSimilarity($fps[$i], $fps[$j]);
                        $pairs++;
                    }
                }
                if ($pairs > 0 && ($sum / $pairs) < 0.4) $cohesionPenalty = 10;
            }

            // Single article guard — bài lẻ viral không được thắng topic lớn (trừ early trend)
            $singleArticlePenalty = (!$isEarlyTrend && $storiesCount < 3 && $avgViral > 85) ? 8 : 0;

            $topicScore = ($avgViral * 0.6)
                + min($storiesCount, 15) * 10
                + $articleCount  * 3
                + min($uniqueSources, 8) * 6
                + $freshScore
                + $entityBoost
                + $topStoryBoost
                + ($isEarlyTrend ? 12 : 0)     // breaking news cực mới được boost qua filter
                - $saturationPenalty
                - $cohesionPenalty
                - $singleArticlePenalty;

            // Bài tốt nhất — dùng lại $articleHours đã tính
            $clusterBonus = min($storiesCount, 10) * 3;
            $best         = null;
            $bestScore    = PHP_INT_MIN;
            foreach ($arts as $i => $a) {
                $url   = $a['link'] ?? '';
                $score = ($viralScores[$url] ?? 0)
                       + $clusterBonus
                       + ($this->isTopSource($a['source'] ?? '') ? 5 : 0)
                       + ($articleHours[$i] <= 3 ? 3 : 0);
                if ($score > $bestScore) { $bestScore = $score; $best = $a; }
            }

            $topics[] = [
                'topic_score'  => $topicScore,
                'best_article' => $best,
                'articles'     => $arts,
            ];
        }

        return $topics;
    }

    private function entityBoost(string $text): int
    {
        $tier1 = preg_match_all(
            '/cowboys|chiefs|eagles|patriots|steelers|packers|bears|giants|49ers'
            . '|mahomes|dak prescott|jalen hurts|brock purdy|jordan love|aaron rodgers'
            . '|tj watt|dk metcalf|travis kelce|saquon barkley|micah parsons|malik nabers'
            . '|taylor swift|kim kardashian|beyonce|elon musk|drake|kanye west|rihanna/i',
            $text
        );

        if ($tier1 >= 2) return 20; // 15 base + 5 extra cho nhiều entity lớn
        if ($tier1 >= 1) return 15;

        if (preg_match(
            '/verstappen|lewis hamilton|leclerc|lando norris|ferrari|red bull racing'
            . '|carlos alcaraz|jannik sinner|coco gauff|iga swiatek|aryna sabalenka'
            . '|rory mcilroy|scottie scheffler|tiger woods/i',
            $text
        )) return 8;

        return 0;
    }

    private function isTopSource(string $source): bool
    {
        return (bool) preg_match(
            '/espn|nfl\.com|nba\.com|cnn|bbc|reuters|associated press|ap news'
            . '|yahoo sports|fox sports|nbc sports|abc|cbssports|bleacher report|the athletic|si\.com/i',
            $source
        );
    }

    private function titleFingerprint(string $title): array
    {
        // array_flip → isset O(1) thay vì in_array O(n)
        static $stop = null;
        $stop ??= array_flip(['the','a','an','and','or','but','in','on','at','to','for','of',
            'with','by','from','is','are','was','were','be','been','have','has','had',
            'will','would','could','should','its','it','this','that','about','after',
            'before','into','than','when','where','who','how','what']);

        $words = preg_split('/\W+/', strtolower($title), -1, PREG_SPLIT_NO_EMPTY);
        $words = array_filter($words, fn($w) => strlen($w) > 2 && !isset($stop[$w]));
        return array_values(array_unique($words));
    }

    private function jaccardSimilarity(array $a, array $b): float
    {
        if (empty($a) || empty($b)) return 0.0;
        $intersection = count(array_intersect($a, $b));
        $union        = count(array_unique(array_merge($a, $b)));
        return $union > 0 ? $intersection / $union : 0.0;
    }
}
