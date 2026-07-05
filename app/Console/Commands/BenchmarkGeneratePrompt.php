<?php

namespace App\Console\Commands;

use App\Services\AI\PromptCompiler\PromptDocumentBuilder;
use App\Services\AI\PromptCompiler\Renderers\KlingRenderer;
use App\Services\AI\ScenePlanner\ScenePlanner;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Generate a fully-resolved Kling prompt from the Sprint 3 pipeline
 * and output the benchmark submission payload (prompt + planner_outputs + instructions).
 *
 * Usage:
 *   php artisan benchmark:generate-prompt
 *   php artisan benchmark:generate-prompt --fixture=nfl_quarterback_throw --seed=12345
 *   php artisan benchmark:generate-prompt --json   (output payload JSON only, pipe-friendly)
 */
class BenchmarkGeneratePrompt extends Command
{
    protected $signature = 'benchmark:generate-prompt
                            {--fixture=nfl_quarterback_throw : Fixture slug to generate prompt for}
                            {--seed=12345 : Kling render seed}
                            {--model=kling-2.1 : Kling model version}
                            {--duration=5 : Clip duration in seconds (5 or 10)}
                            {--json : Output submission payload JSON only}';

    protected $description = 'Sprint 3 — Generate Kling prompt + benchmark submission payload for a fixture';

    /** Fixture slug → DSL definition */
    private const FIXTURE_DSL = [
        'nfl_quarterback_throw' => [
            'scene_title'  => 'Professional NFL quarterback, freezing outdoor stadium, pre-snap focus moment',
            'cam'          => 'CLOSE',
            'move'         => 'P1',
            'lens'         => '85',
            'light'        => 'W1',
            'emo'          => 'POWER',
            'motion_level' => 'high',
            'sub'          => [
                'actor'  => 'NFL quarterback',
                'action' => 'throw',
                'obj'    => 'football',
            ],
            'camera_goal'  => 'snap zoom locks onto quarterback\'s eyes before the throw',
            'shot_order'   => 1,
        ],
        'nba_slam_dunk' => [
            'scene_title'  => 'Professional NBA player, indoor arena, slam dunk at full extension',
            'cam'          => 'CLOSE',
            'move'         => 'P1',
            'lens'         => '85',
            'light'        => 'S1',
            'emo'          => 'POWER',
            'motion_level' => 'high',
            'sub'          => ['actor' => 'NBA basketball player', 'action' => 'dunk', 'obj' => 'basketball'],
            'camera_goal'  => 'capture the dunk at full extension from below',
            'shot_order'   => 1,
        ],
        'soccer_penalty_kick' => [
            'scene_title'  => 'Soccer player, outdoor stadium, penalty kick in final seconds',
            'cam'          => 'CLOSE',
            'move'         => 'P1',
            'lens'         => '85',
            'light'        => 'W2',
            'emo'          => 'TENSE',
            'motion_level' => 'high',
            'sub'          => ['actor' => 'soccer player', 'action' => 'kick', 'obj' => 'ball'],
            'camera_goal'  => 'capture the penalty kick approach and strike',
            'shot_order'   => 1,
        ],
        'luxury_yacht_ocean' => [
            'scene_title'  => 'Luxury superyacht, open ocean, golden hour reveal',
            'cam'          => 'AERIAL',
            'move'         => 'P2',
            'lens'         => '24',
            'light'        => 'G1',
            'emo'          => 'AWE',
            'motion_level' => 'low',
            'sub'          => ['actor' => 'luxury yacht', 'action' => 'cruise', 'obj' => ''],
            'camera_goal'  => 'drone reveal of yacht against vast ocean horizon',
            'shot_order'   => 1,
        ],
    ];

    /**
     * Which instruction catalog codes to track per fixture/scene_category.
     * These must match the bm_instruction_catalog codes seeded in BenchmarkSeeder.
     */
    private const FIXTURE_INSTRUCTIONS = [
        'nfl_quarterback_throw' => [
            // Camera instructions — each maps to a specific beat sentence
            ['catalog_code' => 'snap_zoom',          'beat' => 'hook'],        // velocity_token=burst → "Rapid drone dive — snap zoom..."
            ['catalog_code' => 'push_in',            'beat' => 'escalation'],  // velocity_token=rush → "Fast aggressive push — ..."
            ['catalog_code' => 'abrupt_decel',       'beat' => 'reveal'],      // velocity_token=brake → "Camera decelerates abruptly, holds —"
            ['catalog_code' => 'rack_focus',         'beat' => 'reveal'],      // BeatFusionEngine reveal sentence contains rack focus
            // Physics instructions — extracted from PhysicsPlanner layers
            ['catalog_code' => 'cold_breath',        'beat' => 'hook'],        // W1 weather → micro_motion
            ['catalog_code' => 'jersey_tension',     'beat' => 'reveal'],      // fb_throw interaction layer
            ['catalog_code' => 'crowd_motion_blur',  'beat' => 'escalation'],  // POWER emotion → background
            // Sprint 3 V2 layer instructions — embedded in BeatFusionEngine output
            ['catalog_code' => 'camera_motivation',  'beat' => 'hook'],        // CameraMotivationPlanner purpose clause
            ['catalog_code' => 'atmosphere_active',  'beat' => 'hook'],        // BeatFusionEngine ATMOSPHERE_ACTIVE
            ['catalog_code' => 'eye_hook_implicit',  'beat' => 'hook'],        // BeatFusionEngine EYE_IMPLICIT at hook
            ['catalog_code' => 'eye_payoff_implicit','beat' => 'payoff'],      // BeatFusionEngine EYE_IMPLICIT at payoff
            // Depth composition — per-beat layers from CompositionEvolutionPlanner
            ['catalog_code' => 'depth_hook',         'beat' => 'hook'],
            ['catalog_code' => 'depth_escalation',   'beat' => 'escalation'],
            ['catalog_code' => 'depth_reveal',       'beat' => 'reveal'],
            ['catalog_code' => 'depth_payoff',       'beat' => 'payoff'],
            // Curiosity — CuriosityPlanner subject overrides
            ['catalog_code' => 'identity_withheld',  'beat' => 'hook'],
            ['catalog_code' => 'partial_reveal',     'beat' => 'escalation'],
        ],
        'luxury_yacht_ocean' => [
            ['catalog_code' => 'snap_zoom',          'beat' => 'hook'],
            ['catalog_code' => 'pull_back',          'beat' => 'payoff'],
            ['catalog_code' => 'through_cloud',      'beat' => 'reveal'],
            ['catalog_code' => 'slow_orbit',         'beat' => 'payoff'],
            ['catalog_code' => 'sun_glint_pulse',    'beat' => 'reveal'],
            ['catalog_code' => 'wake_foam',          'beat' => 'escalation'],
            ['catalog_code' => 'camera_motivation',  'beat' => 'hook'],
            ['catalog_code' => 'atmosphere_active',  'beat' => 'hook'],
            ['catalog_code' => 'eye_hook_implicit',  'beat' => 'hook'],
            ['catalog_code' => 'eye_payoff_implicit','beat' => 'payoff'],
            ['catalog_code' => 'depth_hook',         'beat' => 'hook'],
            ['catalog_code' => 'depth_reveal',       'beat' => 'reveal'],
            ['catalog_code' => 'depth_payoff',       'beat' => 'payoff'],
            ['catalog_code' => 'identity_withheld',  'beat' => 'hook'],
            ['catalog_code' => 'partial_reveal',     'beat' => 'escalation'],
        ],
    ];

    public function handle(ScenePlanner $scenePlanner): int
    {
        $fixture  = $this->option('fixture');
        $seed     = $this->option('seed');
        $model    = $this->option('model');
        $duration = (int) $this->option('duration');
        $jsonOnly = $this->option('json');

        $dslBase = self::FIXTURE_DSL[$fixture] ?? null;
        if ($dslBase === null) {
            $this->error("Unknown fixture: {$fixture}");
            $this->line('Available: ' . implode(', ', array_keys(self::FIXTURE_DSL)));
            return self::FAILURE;
        }

        $dsl        = array_merge($dslBase, ['dur' => (float) $duration]);
        $enriched   = $scenePlanner->enrich($dsl);
        $doc        = PromptDocumentBuilder::build($enriched);
        $promptText = KlingRenderer::renderCinematic($doc, $enriched);
        $charCount  = mb_strlen($promptText);

        // ── Resolve instruction variant texts from the enriched pipeline ─────
        $instructionDefs = self::FIXTURE_INSTRUCTIONS[$fixture] ?? [];
        $instructions    = $this->resolveInstructionVariants($instructionDefs, $enriched);

        // ── Build planner_outputs from the beat timeline ──────────────────────
        $plannerOutputs = $this->buildPlannerOutputs($enriched);

        if ($jsonOnly) {
            $payload = [
                'render_uuid'      => (string) Str::uuid(),
                'session_code'     => 'sprint3-baseline',
                'fixture_slug'     => $fixture,
                'model'            => $model,
                'resolution'       => '1080p',
                'duration_seconds' => $duration,
                'fps'              => 24,
                'seed'             => $seed,
                'char_count'       => $charCount,
                'prompt_version'   => 'sprint3_v1',
                'artifact_path'    => "renders/sprint3/{$fixture}/",
                'rendered_at'      => now()->toISOString(),
                'prompt_text'      => $promptText,
                'instructions'     => $instructions,
                'planner_outputs'  => $plannerOutputs,
            ];
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        }

        // ── Human-readable output ─────────────────────────────────────────────
        $this->line('');
        $this->info('════════════════════════════════════════════════════════');
        $this->info("  BENCHMARK PROMPT — {$fixture}");
        $this->info('════════════════════════════════════════════════════════');
        $this->line('');
        $this->line($promptText);
        $this->line('');

        $this->info("── Prompt Stats ─────────────────────────────────────────");
        $this->table(['Metric', 'Value'], [
            ['Characters', $charCount],
            ['Tokens (est.)', (int) ceil($charCount / 4)],
            ['Model', $model],
            ['Duration', "{$duration}s"],
            ['Seed', $seed],
            ['Resolution', '1080p @ 24fps'],
        ]);

        $this->line('');
        $this->info("── Instructions to annotate ({$fixture}) ────────────────");
        $this->table(
            ['beat', 'catalog_code', 'variant_text (excerpt)', 'chars', 'tokens'],
            collect($instructions)->map(fn($i) => [
                $i['beat'],
                $i['catalog_code'],
                mb_strlen($i['variant_text']) > 60
                    ? mb_substr($i['variant_text'], 0, 57) . '...'
                    : $i['variant_text'],
                mb_strlen($i['variant_text']),
                (int) ceil(mb_strlen($i['variant_text']) / 4),
            ])->toArray()
        );

        $this->line('');
        $this->info("── Planner outputs ({$fixture}) ─────────────────────────");
        $this->table(
            ['planner', 'beat', 'raw_text (excerpt)'],
            collect($plannerOutputs)->map(fn($p) => [
                $p['planner_name'],
                $p['beat'],
                mb_strlen($p['raw_text']) > 70
                    ? mb_substr($p['raw_text'], 0, 67) . '...'
                    : $p['raw_text'],
            ])->toArray()
        );

        $uuid = (string) Str::uuid();
        $this->line('');
        $this->info("── Benchmark submission payload (POST /api/benchmark/render-result) ──");
        $this->line("render_uuid: {$uuid}");
        $this->line("session_code: sprint3-baseline");
        $this->line("fixture_slug: {$fixture}");
        $this->line("seed: {$seed}");
        $this->line("char_count: {$charCount}");
        $this->line('');
        $this->comment('Run with --json to get the full payload for Python submission client.');
        $this->line('');

        return self::SUCCESS;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Extract the actual variant text for each instruction from the rendered prompt
     * and enriched planner data. For camera instructions: find the fused beat sentence.
     * For physics instructions: find the matching physics phrase.
     */
    private function resolveInstructionVariants(array $defs, array $enriched): array
    {
        $fusedBeats   = collect($enriched['timeline'] ?? [])
            ->keyBy('beat')
            ->all();
        $physics      = $enriched['physics'] ?? [];

        // Physics phrase resolvers: keyword → physics layer to search
        $physicsResolvers = [
            'cold_breath'      => ['layer' => 'micro_motion', 'keyword' => 'breath',  'fallback' => 'cold breath vapor visible on each exhale'],
            'crowd_motion_blur'=> ['layer' => 'background',   'keyword' => 'crowd',   'fallback' => 'crowd rising — human motion blur behind the action'],
            'wake_foam'        => ['layer' => 'interaction',  'keyword' => 'wake',    'fallback' => 'wake foam and spray trailing behind the vessel'],
            'jersey_tension'   => ['layer' => 'interaction',  'keyword' => 'jersey',  'fallback' => 'jersey stretches at shoulder seam during full torso rotation'],
            'sun_glint_pulse'  => ['layer' => 'atmosphere',   'keyword' => 'glint',   'fallback' => 'sun glint pulsing rhythmically on water surface'],
            'exhaust_plume'    => ['layer' => 'atmosphere',   'keyword' => 'exhaust', 'fallback' => 'engine exhaust plume trailing from rocket'],
            'dust_lift'        => ['layer' => 'atmosphere',   'keyword' => 'dust',    'fallback' => 'dust lifting from surface on impact'],
        ];

        // Camera motivation — extract from cameraMotivation plan (not from fused sentence)
        $motivationByBeat = collect($enriched['camera_motivation']['beats'] ?? [])
            ->pluck('motivation', 'beat')
            ->all();

        $curiosityStates = $enriched['curiosity']['beat_states'] ?? [];

        $resolved = [];
        foreach ($defs as $def) {
            $code = $def['catalog_code'];
            $beat = $def['beat'];

            if (isset($physicsResolvers[$code])) {
                $pr          = $physicsResolvers[$code];
                $variantText = collect($physics[$pr['layer']] ?? [])
                    ->first(fn($p) => str_contains(strtolower($p), $pr['keyword']))
                    ?? $pr['fallback'];
            } elseif ($code === 'camera_motivation') {
                // The purpose clause alone — not the full fused sentence
                $variantText = $motivationByBeat[$beat] ?? ($fusedBeats[$beat]['camera'] ?? "Camera motivation for {$beat}");
            } elseif (in_array($code, ['identity_withheld', 'partial_reveal'], true)) {
                $beatState   = $curiosityStates[$beat] ?? [];
                $variantText = $beatState['subject_override'] ?? ($fusedBeats[$beat]['subject'] ?? "Curiosity override for {$beat}");
            } elseif (in_array($code, ['depth_hook', 'depth_escalation', 'depth_reveal', 'depth_payoff'], true)) {
                // Depth composition — subject sentence at this beat captures depth layers
                $variantText = $fusedBeats[$beat]['subject'] ?? "Depth composition for {$beat}";
            } else {
                // All camera + eye + atmosphere instructions: the fused camera sentence for this beat
                $variantText = $fusedBeats[$beat]['camera'] ?? "Camera instruction for {$beat}";
            }

            $resolved[] = [
                'catalog_code' => $code,
                'beat'         => $beat,
                'variant_text' => trim((string) $variantText),
            ];
        }

        return $resolved;
    }

    /**
     * Build planner_outputs from the enriched DSL's cinematic beat timeline.
     * One row per (planner, beat) that contributed a distinct sentence.
     */
    private function buildPlannerOutputs(array $enriched): array
    {
        $outputs = [];

        // BeatFusionEngine produced the fused camera sentence for each beat
        foreach ($enriched['timeline'] ?? [] as $seg) {
            $beat = $seg['beat'] ?? '';
            if ($beat === '' || ($seg['camera'] ?? '') === '') {
                continue;
            }
            $outputs[] = [
                'planner_name' => 'BeatFusionEngine',
                'beat'         => $beat,
                'raw_text'     => $seg['camera'],
            ];
        }

        // CameraMotivationPlanner contributed purpose clauses
        foreach ($enriched['camera_motivation']['beats'] ?? [] as $mo) {
            $beat = $mo['beat'] ?? '';
            if ($beat === '' || ($mo['motivation'] ?? '') === '') {
                continue;
            }
            $outputs[] = [
                'planner_name' => 'CameraMotivationPlanner',
                'beat'         => $beat,
                'raw_text'     => $mo['motivation'],
            ];
        }

        // PhysicsPlanner contributed micro_motion and interaction phrases
        $allPhysics = array_merge(
            $enriched['physics']['micro_motion'] ?? [],
            $enriched['physics']['interaction']  ?? [],
            $enriched['physics']['atmosphere']   ?? [],
        );
        if (! empty($allPhysics)) {
            // Attribute physics phrases to the reveal beat (peak action physics)
            $outputs[] = [
                'planner_name' => 'PhysicsPlanner',
                'beat'         => 'reveal',
                'raw_text'     => implode('. ', array_slice($allPhysics, 0, 3)) . '.',
            ];
        }

        // RevealPlanner — if a non-empty reveal mechanism was generated
        $revealMech = $enriched['reveal']['mechanism'] ?? '';
        $revealCam  = $enriched['reveal']['camera_instruction'] ?? '';
        if ($revealMech !== '' || $revealCam !== '') {
            $outputs[] = [
                'planner_name' => 'RevealPlanner',
                'beat'         => 'reveal',
                'raw_text'     => $revealCam !== '' ? $revealCam : $revealMech,
            ];
        }

        return $outputs;
    }
}
