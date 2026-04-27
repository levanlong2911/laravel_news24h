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
            foreach ($this->serpApi->searchNews($query, 20) as $item) {
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

        // Score tất cả bài — kể cả bài không qua filterAndScore
        $scoreMap = [];
        foreach ($allRaw as $item) {
            $url = $item['link'] ?? '';
            if ($url) $scoreMap[$url] = $this->serpApi->scoreArticle($item);
        }

        // filterAndScore cho top: lọc theo thời gian + score ≥ 20
        $scored = $this->serpApi->filterAndScore($allRaw);

        // Override scoreMap với điểm chính xác hơn từ filterAndScore
        foreach ($scored as $item) {
            $url = $item['link'] ?? '';
            if ($url) $scoreMap[$url] = [
                'quality_score' => $item['quality_score'] ?? 0,
                'fb_score'      => $item['fb_score'] ?? 0,
            ];
        }

        // Score tất cả candidates bằng ViralScoreService trước khi sort
        $viralScores = [];
        foreach ($scored as $item) {
            $url = $item['link'] ?? '';
            if ($url) $viralScores[$url] = $this->viralScore->calculateFromRaw($item, $kw)['score'];
        }

        if (!empty($scored)) {
            usort($scored, fn($a, $b) =>
                ($viralScores[$b['link'] ?? ''] ?? 0) <=> ($viralScores[$a['link'] ?? ''] ?? 0)
            );
        }

        $top10 = !empty($scored)
            ? array_slice($this->deduplicateByTopic($scored), 0, 10)
            : [];

        if (empty($top10)) {
            Log::warning("[FetchNews] All filtered out: {$kw->name}");
        }

        $recent30 = $this->serpApi->filterRecent($allRaw, 30, $kw->category_id ?? '');

        if (empty($top10) && empty($recent30)) {
            Log::warning("[FetchNews] Nothing to save: {$kw->name}");
            return ['saved' => 0, 'top' => 0, 'recent' => 0];
        }

        // Merge, dedup by URL (top > recent)
        $byUrl = [];
        foreach ($top10 as $item) {
            $url = $item['link'] ?? '';
            if ($url) $byUrl[$url] = array_merge($item, ['list_type' => 'top']);
        }
        foreach ($recent30 as $item) {
            $url = $item['link'] ?? '';
            if ($url && !isset($byUrl[$url])) {
                $byUrl[$url] = array_merge($item, ['list_type' => 'recent']);
            }
        }

        // Score recent30 items chưa có trong viralScores
        foreach ($byUrl as $url => $item) {
            if (!isset($viralScores[$url])) {
                $viralScores[$url] = $this->viralScore->calculateFromRaw($item, $kw)['score'];
            }
        }

        // Skip URLs đã có trong DB
        $existing = RawArticle::whereIn('url_hash', array_map('md5', array_keys($byUrl)))
            ->pluck('url_hash')->flip()->all();

        $rows = [];
        $now  = now();

        foreach ($byUrl as $url => $item) {
            if (isset($existing[md5($url)])) continue;

            $fbScore = $viralScores[$url];

            $rows[] = [
                'id'             => Str::uuid()->toString(),
                'keyword_id'     => $kw->id,
                'title'          => $item['title'] ?? '',
                'url'            => $url,
                'url_hash'       => md5($url),
                'snippet'        => $item['snippet'] ?? '',
                'source'         => $item['source'] ?? '',
                'source_icon'    => $item['source_icon'] ?? '',
                'thumbnail'      => $item['thumbnail'] ?? '',
                'viral_score'    => (int) ($item['quality_score'] ?? $scoreMap[$url]['quality_score'] ?? 0),
                'fb_score'       => $fbScore,
                'position'       => (int) ($item['position'] ?? 0),
                'published_date' => $item['date'] ?? '',
                'stories_count'  => count($item['stories'] ?? []),
                'top_story'      => !empty($item['stories']),
                'list_type'      => $item['list_type'],
                'status'         => 'pending',
                'expires_at'     => $now->copy()->addHours(24),
                'created_at'     => $now,
                'updated_at'     => $now,
            ];
        }

        $saved = count($rows);
        if ($saved > 0) {
            RawArticle::insert($rows);
        }

        Log::info("[FetchNews] Saved {$saved} for: {$kw->name} (top=" . count($top10) . " recent=" . count($recent30) . ")");

        return ['saved' => $saved, 'top' => count($top10), 'recent' => count($recent30)];
    }

    private function deduplicateByTopic(array $articles): array
    {
        $result   = [];
        $seenKeys = [];

        foreach ($articles as $article) {
            $fp          = $this->titleFingerprint($article['title'] ?? '');
            $isDuplicate = false;

            foreach ($seenKeys as $existingFp) {
                if ($this->jaccardSimilarity($fp, $existingFp) >= 0.55) {
                    $isDuplicate = true;
                    break;
                }
            }

            if (!$isDuplicate) {
                $result[]   = $article;
                $seenKeys[] = $fp;
            }
        }

        return $result;
    }

    private function titleFingerprint(string $title): array
    {
        static $stop = ['the','a','an','and','or','but','in','on','at','to','for','of',
            'with','by','from','is','are','was','were','be','been','have','has','had',
            'will','would','could','should','its','it','this','that','about','after',
            'before','into','than','when','where','who','how','what'];

        $words = preg_split('/\W+/', strtolower($title), -1, PREG_SPLIT_NO_EMPTY);
        $words = array_filter($words, fn($w) => strlen($w) > 2 && !in_array($w, $stop));
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
