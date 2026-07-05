<?php

namespace App\Console\Commands;

use App\Http\Requests\Benchmark\StoreRenderResultRequest;
use App\Models\Benchmark\BmInstructionInstance;
use App\Models\Benchmark\BmPlannerOutput;
use App\Models\Benchmark\BmRender;
use App\Models\Benchmark\BmRenderPlanner;
use App\Models\Benchmark\BmRenderScore;
use App\Services\Benchmark\RenderResultService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Phase A.3.5 — End-to-End Integration Test
 *
 * Usage:
 *   php artisan benchmark:test-submit
 *   php artisan benchmark:test-submit --repeat=100 --cleanup
 *
 * --repeat=N   Submit N times with same UUID (idempotency stress test)
 * --cleanup    Delete test render(s) after verification
 */
class BenchmarkTestSubmit extends Command
{
    protected $signature   = 'benchmark:test-submit
                                {--repeat=1 : Number of times to submit (same UUID for idempotency test)}
                                {--cleanup  : Delete test render after verification}';
    protected $description = 'Phase A.3.5 — Submit a synthetic render and verify end-to-end pipeline';

    public function handle(RenderResultService $service): int
    {
        $repeat  = (int) $this->option('repeat');
        $uuid    = (string) Str::uuid(); // same UUID for all repeats
        $payload = $this->samplePayload($uuid);
        $pass    = true;

        $this->info("Phase A.3.5 — Integration Test (repeat={$repeat})");
        $this->line("  render_uuid: {$uuid}");
        $this->line("  instructions: " . count($payload['instructions']));
        $this->line("  planner_outs: " . count($payload['planner_outputs']));
        $this->line('');

        // ── Submit N times (idempotency check) ───────────────────────────────
        $times   = [];
        $results = [];
        for ($i = 1; $i <= $repeat; $i++) {
            $t0        = microtime(true);
            $results[] = $service->store($payload);
            $times[]   = round((microtime(true) - $t0) * 1000, 1);
        }

        $firstResult = $results[0];
        $this->info("✓ First submit: 201");
        $this->line("  render_id:     {$firstResult['render_id']}");
        $this->line("  already_existed: " . ($firstResult['already_existed'] ? 'true' : 'false'));
        $this->line("  instruction_count: {$firstResult['instruction_count']}");

        if ($repeat > 1) {
            $subsequent = array_slice($results, 1);
            $allIdempotent = collect($subsequent)->every(fn($r) => $r['already_existed'] === true);
            $pass &= $this->check(
                'Idempotency',
                $allIdempotent,
                "All {$repeat} retries returned already_existed=true"
            );

            // All should return same render_id
            $sameId = collect($results)->every(fn($r) => $r['render_id'] === $firstResult['render_id']);
            $pass  &= $this->check('Same render_id across retries', $sameId);

            $avgMs  = round(array_sum($times) / count($times), 1);
            $maxMs  = max($times);
            $this->line("  Timing: avg={$avgMs}ms  max={$maxMs}ms");
        }

        // ── DB row verification ───────────────────────────────────────────────
        $renderId = $firstResult['render_id'];
        $this->line('');

        $pass &= $this->check('bm_renders row',           BmRender::where('uuid', $uuid)->exists());
        $pass &= $this->check('bm_render_scores row',     BmRenderScore::where('render_id', $renderId)->exists());

        $instCount = BmInstructionInstance::where('render_id', $renderId)->count();
        $pass     &= $this->check("bm_instruction_instances ({$instCount} rows)", $instCount === count($payload['instructions']));

        $poCount = BmPlannerOutput::where('render_id', $renderId)->count();
        $pass   &= $this->check("bm_planner_outputs ({$poCount} rows)", $poCount === count($payload['planner_outputs']));

        $plannerSnapshots = BmRenderPlanner::where('render_id', $renderId)->count();
        $pass &= $this->check("bm_render_planners ({$plannerSnapshots} planner fingerprints)", $plannerSnapshots > 0);

        // Idempotency: no duplicate rows on retry
        if ($repeat > 1) {
            $pass &= $this->check(
                'No duplicate instruction_instances on retry',
                BmInstructionInstance::where('render_id', $renderId)->count() === count($payload['instructions'])
            );
        }

        // ── Instruction instance detail ───────────────────────────────────────
        $this->line('');
        $this->line('Instruction instances:');
        $instances = BmInstructionInstance::with('catalog.planner')
            ->where('render_id', $renderId)->get();

        $this->table(
            ['beat', 'instruction', 'planner', 'chars', 'tokens', 'observed'],
            $instances->map(fn($i) => [
                $i->beat,
                $i->catalog->code ?? '?',
                $i->catalog->planner->name ?? '?',
                $i->char_length,
                $i->estimated_token_cost,
                $i->observed === null ? 'null (pending)' : $i->observed,
            ])->toArray()
        );

        // ── Planner fingerprint snapshot ──────────────────────────────────────
        $this->line('Planner fingerprints snapshot:');
        $snapshots = BmRenderPlanner::with('planner')->where('render_id', $renderId)->get();
        $this->table(
            ['planner', 'fingerprint (first 16 chars)'],
            $snapshots->map(fn($s) => [
                $s->planner->name ?? '?',
                $s->fingerprint ? substr($s->fingerprint, 0, 16) . '...' : 'null',
            ])->toArray()
        );

        // ── Prompt efficiency preview ─────────────────────────────────────────
        $render = BmRender::find($renderId);
        $this->line('');
        $this->line("char_count={$render->char_count}  annotationProgress=" . ($render->annotationProgress() * 100) . '%');

        // ── Cleanup ───────────────────────────────────────────────────────────
        if ($this->option('cleanup')) {
            BmRender::find($renderId)?->delete();
            $this->line('');
            $this->warn('Test render deleted (--cleanup).');
        } else {
            $this->line('');
            $this->line('Render kept in DB. Re-run with --cleanup to remove it.');
        }

        $this->line('');
        return $pass ? self::SUCCESS : self::FAILURE;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function check(string $label, bool $ok, string $detail = ''): bool
    {
        $suffix = $detail ? " — {$detail}" : '';
        if ($ok) {
            $this->info("  ✓ {$label}{$suffix}");
        } else {
            $this->error("  ✗ {$label}{$suffix}");
        }
        return $ok;
    }

    private function samplePayload(string $uuid): array
    {
        return [
            'render_uuid'      => $uuid,
            'session_code'     => 'sprint3-baseline',
            'fixture_slug'     => 'nfl_quarterback_throw',
            'model'            => 'kling-2.1',
            'resolution'       => '1080p',
            'duration_seconds' => 5,
            'fps'              => 24,
            'seed'             => '12345',
            'char_count'       => 2471,
            'prompt_version'   => 'sprint3_v1',
            'artifact_path'    => 'renders/sprint3/athletic/nfl/test-run/',
            'rendered_at'      => now()->toISOString(),

            'instructions' => [
                ['catalog_code' => 'snap_zoom',         'beat' => 'hook',        'variant_text' => 'snap zoom locks onto the quarterback\'s eyes'],
                ['catalog_code' => 'push_in',           'beat' => 'escalation',  'variant_text' => 'camera pushes in as the body commits toward an action that cannot be recalled'],
                ['catalog_code' => 'rack_focus',        'beat' => 'reveal',      'variant_text' => 'rack focus pulls from ambient blur'],
                ['catalog_code' => 'slow_orbit',        'beat' => 'payoff',      'variant_text' => 'camera decelerates abruptly, holds at stadium scale'],
                ['catalog_code' => 'cold_breath',       'beat' => 'hook',        'variant_text' => 'cold breath visible in freezing stadium air'],
                ['catalog_code' => 'crowd_motion_blur', 'beat' => 'escalation',  'variant_text' => 'crowd rising — human motion blur behind the action'],
            ],

            'planner_outputs' => [
                ['planner_name' => 'CameraMotivationPlanner', 'beat' => 'hook',    'raw_text' => 'to compress the entire world to a single point of will'],
                ['planner_name' => 'BeatFusionEngine',        'beat' => 'hook',    'raw_text' => 'Snap zoom locks onto the quarterback\'s eyes to compress the entire world to a single point of will, dark amber stadium light swallowing every surrounding distraction — only the face and its locked resolve exist in the frame.'],
                ['planner_name' => 'PhysicsPlanner',          'beat' => 'hook',    'raw_text' => 'Cold breath visible in freezing stadium air — condensation forming at each exhale.'],
                ['planner_name' => 'BeatFusionEngine',        'beat' => 'payoff',  'raw_text' => 'Camera decelerates abruptly, holds in warm celebration light — the stadium scale asserts itself beyond the athlete and the throw.'],
            ],
        ];
    }
}
