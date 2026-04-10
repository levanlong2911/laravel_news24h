<?php

namespace App\Jobs;

use App\Models\Keyword;
use App\Models\RawArticle;
use App\Services\Admin\SerpApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * BƯỚC 1: Fetch top 10 từ Google News → lưu vào raw_articles (không AI).
 * User tự chọn bài nào muốn viết bằng cách nhấn "Generate" trên UI.
 */
class ProcessKeywordJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 90;

    public function __construct(public readonly Keyword $keyword) {}

    public function handle(SerpApiService $serpApi): void
    {
        $kw = $this->keyword;
        Log::info("[FetchNews] Start: {$kw->name}");

        // ── Fetch Google News — dùng search_keyword nếu có, fallback name + ' news'
        $query    = !empty($kw->search_keyword) ? $kw->search_keyword : ($kw->name . ' news');
        $allRaw   = [];
        $seenUrls = [];

        foreach ($serpApi->searchNews($query, 20) as $item) {
            $link = $item['link'] ?? '';
            if ($link && !isset($seenUrls[$link])) {
                $seenUrls[$link] = true;
                $allRaw[]        = $item;
            }
        }

        if (empty($allRaw)) {
            Log::warning("[FetchNews] No results: {$kw->name}");
            return;
        }

        // ── Score + rank ──────────────────────────────────────────────────────
        $scored = $serpApi->filterAndScore($allRaw);

        if (empty($scored)) {
            Log::warning("[FetchNews] All filtered out: {$kw->name}");
            return;
        }

        // ── BƯỚC 4: Dedup topic ───────────────────────────────────────────────
        $dedupedByTopic = $this->deduplicateByTopic($scored);
        $top10          = array_slice($dedupedByTopic, 0, 10);

        Log::info(sprintf(
            "[FetchNews] %s: raw=%d → scored=%d → deduped=%d → saved=%d",
            $kw->name, count($allRaw), count($scored), count($dedupedByTopic), count($top10)
        ));

        // ── BƯỚC 5: Lưu vào raw_articles (dedup theo URL hash) ───────────────
        // Load existing hashes 1 lần — tránh N+1 query
        $hashes   = array_map(fn($i) => md5($i['link'] ?? ''), $top10);
        $existing = RawArticle::whereIn('url_hash', $hashes)->pluck('url_hash')->flip()->all();
        $saved    = 0;

        foreach ($top10 as $item) {
            $urlHash = md5($item['link'] ?? '');

            if (isset($existing[$urlHash])) {
                continue;
            }

            RawArticle::create([
                'keyword_id'     => $kw->id,
                'title'          => $item['title']    ?? '',
                'url'            => $item['link']     ?? '',
                'url_hash'       => $urlHash,
                'snippet'        => $item['snippet']  ?? '',
                'source'         => $item['source']   ?? '',
                'source_icon'    => $item['source_icon'] ?? '',
                'thumbnail'      => $item['thumbnail'] ?? '',
                'viral_score'    => (int)($item['quality_score'] ?? 0),
                'position'       => (int)($item['position']      ?? 99),
                'published_date' => $this->parsePublishedDate($item['date'] ?? ''),
                'stories_count'  => count($item['stories'] ?? []),
                'top_story'      => !empty($item['stories']),
                'status'         => 'pending',
                'expires_at'     => now()->addHours(24),
            ]);

            $saved++;
        }

        Log::info("[FetchNews] Saved {$saved} new raw articles for: {$kw->name}");
    }

    // ── Dedup topic ───────────────────────────────────────────────────────────

    private function deduplicateByTopic(array $articles): array
    {
        // KHÔNG loại bỏ top_story articles — chúng đã được expand từ cluster
        // Chỉ Jaccard dedup để tránh bài cùng topic hoàn toàn
        $filtered = $articles;

        // Jaccard title similarity — bỏ bài cùng topic (>55% từ trùng)
        $result   = [];
        $seenKeys = [];

        foreach ($filtered as $article) {
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

    /**
     * Convert SerpAPI date string → absolute ISO timestamp để tính đúng "X giờ trước".
     * SerpAPI trả về: "3 hours ago", "1 day ago", "04/08/2026, 10:00 AM, +0000 UTC"
     * Nếu lưu thẳng "3 hours ago" → sau 4h fetch vẫn hiển thị "3h ago" thay vì "7h ago"
     */
    private function parsePublishedDate(string $date): string
    {
        if (empty($date)) return '';

        $d = strtolower(trim($date));

        // Relative string từ SerpAPI: "X minutes/hours/days ago"
        if (preg_match('/^(\d+)\s+(minute|hour|day)s?\s+ago$/i', $d, $m)) {
            $n    = (int)$m[1];
            $unit = $m[2];
            $ts   = match($unit) {
                'minute' => now()->subMinutes($n),
                'hour'   => now()->subHours($n),
                'day'    => now()->subDays($n),
                default  => now(),
            };
            return $ts->toIso8601String();
        }

        // Absolute date từ SerpAPI: "04/08/2026, 10:00 AM, +0000 UTC"
        $cleaned = trim(preg_replace('/,?\s*\+\d{4}\s*UTC$/i', '', $date));
        $parsed  = strtotime($cleaned);
        if ($parsed !== false) {
            return date('c', $parsed); // ISO 8601
        }

        return $date; // fallback giữ nguyên
    }

    public function failed(\Throwable $e): void
    {
        Log::error("[FetchNews] Failed: {$this->keyword->name} — {$e->getMessage()}");
    }
}
