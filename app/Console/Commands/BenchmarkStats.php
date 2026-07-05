<?php

namespace App\Console\Commands;

use App\Models\Benchmark\BmFixture;
use App\Models\Benchmark\BmInstruction;
use App\Models\Benchmark\BmInstructionInstance;
use App\Models\Benchmark\BmPlanner;
use App\Models\Benchmark\BmRender;
use App\Models\Benchmark\BmSession;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BenchmarkStats extends Command
{
    protected $signature   = 'benchmark:stats {--session= : Filter by session code}';
    protected $description = 'Show benchmark database statistics at a glance';

    public function handle(): int
    {
        $sessionCode = $this->option('session');
        $renderQuery = BmRender::query();
        if ($sessionCode) {
            $renderQuery->whereHas('session', fn($q) => $q->where('code', $sessionCode));
        }

        $totalRenders  = (clone $renderQuery)->count();
        $annotated     = (clone $renderQuery)->whereNotNull('annotated_at')->count();

        $this->line('');
        $this->info('=== Benchmark Database Stats ===');
        if ($sessionCode) {
            $this->line("  Session filter: {$sessionCode}");
        }
        $this->line('');

        // ── Counts ────────────────────────────────────────────────────────────
        $this->table(['Entity', 'Count'], [
            ['Sessions',     BmSession::count()],
            ['Fixtures',     BmFixture::count()],
            ['Planners',     BmPlanner::count()],
            ['Instructions', BmInstruction::where('deprecated_in', null)->count()],
            ['Renders',      $totalRenders],
            ['Annotated',    $annotated],
        ]);

        if ($totalRenders === 0) {
            $this->line('No renders yet. Run the render pipeline to populate data.');
            return self::SUCCESS;
        }

        // ── Annotation progress ───────────────────────────────────────────────
        $totalInst    = BmInstructionInstance::whereIn('render_id', (clone $renderQuery)->pluck('id'))->count();
        $observedInst = BmInstructionInstance::whereIn('render_id', (clone $renderQuery)->pluck('id'))->whereNotNull('observed')->count();
        $pct          = $totalInst > 0 ? round($observedInst / $totalInst * 100, 1) : 0;

        $this->line('');
        $this->line("Annotation progress: {$observedInst}/{$totalInst} instances ({$pct}%)");

        // ── Prompt stats ──────────────────────────────────────────────────────
        $charStats = (clone $renderQuery)
            ->selectRaw('AVG(char_count) as avg_chars, MIN(char_count) as min_chars, MAX(char_count) as max_chars')
            ->first();

        $this->line('');
        $this->table(['Metric', 'Value'], [
            ['Avg char_count', round($charStats->avg_chars ?? 0)],
            ['Min char_count', $charStats->min_chars ?? 0],
            ['Max char_count', $charStats->max_chars ?? 0],
        ]);

        // ── Score summary (if any scored) ─────────────────────────────────────
        $scoreStats = DB::table('bm_render_scores')
            ->join('bm_renders', 'bm_renders.id', '=', 'bm_render_scores.render_id')
            ->when($sessionCode, fn($q) => $q->whereIn('bm_renders.id', (clone $renderQuery)->pluck('id')))
            ->whereNotNull('overall')
            ->selectRaw('AVG(overall) as avg, MIN(overall) as min, MAX(overall) as max, COUNT(*) as cnt')
            ->first();

        if ($scoreStats && $scoreStats->cnt > 0) {
            $this->line('');
            $this->info("Scores ({$scoreStats->cnt} scored):");
            $this->table(['Metric', 'Value'], [
                ['Avg overall',  round($scoreStats->avg, 1)],
                ['Min overall',  $scoreStats->min],
                ['Max overall',  $scoreStats->max],
            ]);
        }

        // ── Instruction ROI from materialized stats (O(1) regardless of DB size) ──
        if ($observedInst > 0) {
            $instStats = DB::table('bm_instruction_stats')
                ->join('bm_instruction_catalog', 'bm_instruction_stats.catalog_id', '=', 'bm_instruction_catalog.id')
                ->where('bm_instruction_stats.attempts', '>=', 5)
                ->groupBy('bm_instruction_catalog.code')
                ->selectRaw('
                    bm_instruction_catalog.code,
                    SUM(bm_instruction_stats.attempts)  as total_attempts,
                    SUM(bm_instruction_stats.successes) as total_successes,
                    SUM(bm_instruction_stats.total_token_cost) as total_tokens,
                    ROUND(SUM(successes) / SUM(attempts) * 100, 1) as success_pct,
                    ROUND(
                        (SUM(successes) / SUM(attempts) * 100) /
                        (SUM(total_token_cost) / SUM(attempts))
                    , 2) as roi
                ')
                ->orderByDesc('roi')
                ->limit(10)
                ->get();

            if ($instStats->isNotEmpty()) {
                $this->line('');
                $this->info('Instruction ROI (materialized · min 5 attempts · sorted by ROI):');
                $this->table(
                    ['instruction', 'attempts', 'success%', 'avg_tokens', 'roi'],
                    $instStats->map(fn($r) => [
                        $r->code,
                        $r->total_attempts,
                        $r->success_pct . '%',
                        round($r->total_tokens / $r->total_attempts, 1),
                        $r->roi,
                    ])->toArray()
                );
            }
        }

        $this->line('');
        return self::SUCCESS;
    }
}
