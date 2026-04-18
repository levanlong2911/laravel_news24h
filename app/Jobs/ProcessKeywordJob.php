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
use Illuminate\Support\Str;

/**
 * Fetch top 10 từ Google News (SerpAPI) → lưu metadata vào raw_articles.
 * Không crawl content ở đây — content crawl khi user click Download.
 */
class ProcessKeywordJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 120;

    public function __construct(public readonly Keyword $keyword) {}

    public function handle(SerpApiService $serpApi): void
    {
        $kw = $this->keyword;
        Log::info("[FetchNews] Start: {$kw->name}");

        // ── Fetch Google News ─────────────────────────────────────────────────
        $query  = !empty($kw->search_keyword) ? $kw->search_keyword : ($kw->name . ' news');
        $allRaw = [];
        $seen   = [];

        foreach ($serpApi->searchNews($query, 20) as $item) {
            $link = $item['link'] ?? '';
            if ($link && !isset($seen[$link])) {
                $seen[$link] = true;
                $allRaw[]    = $item;
            }
        }

        if (empty($allRaw)) {
            Log::warning("[FetchNews] No results: {$kw->name}");
            return;
        }

        // ── Top 10: quality-scored ────────────────────────────────────────────
        $scored = $serpApi->filterAndScore($allRaw);
        $top10  = !empty($scored)
            ? array_slice($this->deduplicateByTopic($scored), 0, 10)
            : [];

        if (empty($top10)) {
            Log::warning("[FetchNews] All filtered out: {$kw->name}");
        }

        // ── Recent 20: date-sorted, minimal filter ────────────────────────────
        $recent20 = $serpApi->filterRecent($allRaw, 20);

        if (empty($top10) && empty($recent20)) {
            Log::warning("[FetchNews] Nothing to save: {$kw->name}");
            return;
        }

        // ── Merge, dedup by URL (top > recent priority) ───────────────────────
        // Keyed by URL so same article in both lists is only saved once as 'top'
        $byUrl = [];
        foreach ($top10 as $item) {
            $url = $item['link'] ?? '';
            if ($url) $byUrl[$url] = array_merge($item, ['list_type' => 'top']);
        }
        foreach ($recent20 as $item) {
            $url = $item['link'] ?? '';
            if ($url && !isset($byUrl[$url])) {
                $byUrl[$url] = array_merge($item, ['list_type' => 'recent']);
            }
        }

        // ── Skip URLs already in DB ───────────────────────────────────────────
        $existing = RawArticle::whereIn('url_hash', array_map('md5', array_keys($byUrl)))
            ->pluck('url_hash')->flip()->all();

        $rows = [];
        $now  = now();

        foreach ($byUrl as $url => $item) {
            if (isset($existing[md5($url)])) continue;

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
                'viral_score'    => (int) ($item['quality_score'] ?? 0),
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

        Log::info(sprintf(
            '[FetchNews] Saved %d for: %s (top=%d recent=%d)',
            $saved, $kw->name, count($top10), count($recent20)
        ));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

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

    public function failed(\Throwable $e): void
    {
        Log::error("[FetchNews] Failed: {$this->keyword->name} — {$e->getMessage()}");
    }
}
