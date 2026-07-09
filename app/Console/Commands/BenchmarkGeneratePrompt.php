<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Services\Admin\ClaudeWriterService;
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
                            {--article= : Article UUID — Claude haiku extracts DSL from article content (overrides --fixture)}
                            {--seed=12345 : Kling render seed}
                            {--model=kling-2.1 : Kling model version}
                            {--duration=5 : Clip duration in seconds (5 or 10)}
                            {--renderer=v2 : Prompt renderer: v1=cinematic prose (~680 tokens), v2=priority layers (~250 tokens)}
                            {--max-tokens=300 : Token budget for v2 renderer}
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

    public function handle(ScenePlanner $scenePlanner, ClaudeWriterService $claude): int
    {
        $fixture   = $this->option('fixture');
        $articleId = $this->option('article');
        $seed      = $this->option('seed');
        $model     = $this->option('model');
        $duration  = (int) $this->option('duration');
        $jsonOnly  = $this->option('json');

        if ($articleId) {
            $dslBase = $this->dslFromArticle($articleId, $claude);
            if ($dslBase === null) {
                return self::FAILURE;
            }
            $fixture = 'article_' . substr($articleId, 0, 8);
        } else {
            $dslBase = self::FIXTURE_DSL[$fixture] ?? null;
            if ($dslBase === null) {
                $this->error("Unknown fixture: {$fixture}");
                $this->line('Available: ' . implode(', ', array_keys(self::FIXTURE_DSL)));
                return self::FAILURE;
            }
        }

        $dsl        = array_merge($dslBase, ['dur' => (float) $duration]);
        $enriched   = $scenePlanner->enrich($dsl);
        $doc        = PromptDocumentBuilder::build($enriched);
        $renderer   = (string) $this->option('renderer');
        $maxTokens  = (int)   $this->option('max-tokens');
        $promptText = $renderer === 'v1'
            ? KlingRenderer::renderCinematic($doc, $enriched)
            : KlingRenderer::renderLayered($doc, $enriched, $maxTokens);
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

    /**
     * Call Claude haiku to extract cinematic DSL fields from a raw article.
     * Returns array compatible with FIXTURE_DSL, or null on failure.
     */
    private function dslFromArticle(string $articleId, ClaudeWriterService $claude): ?array
    {
        $article = Article::find($articleId);
        if (!$article) {
            $this->error("Article not found: {$articleId}");
            return null;
        }

        $excerpt = mb_substr(strip_tags((string) ($article->content ?? '')), 0, 1500);

        $prompt = <<<PROMPT
You are a cinematic director. Read this news article and choose DSL parameters for a single 5-second Kling video clip that best represents the article visually.

Article title: {$article->title}
Article content: {$excerpt}

Return ONLY a JSON object with these exact fields (no explanation, no markdown):
{
  "scene_title": "one sentence describing the most visual scene",
  "cam": "one of: WIDE|MEDIUM|CLOSE|MACRO|ORBITAL|TRACKING|AERIAL|POV",
  "move": "one of: STATIC|P1|P2|D1|D2|O1|O2|H1|T1|T2",
  "lens": "one of: 24|35|50|85|135|200",
  "light": "one of: W1|W2|G1|N1|N2|D1|S1|S2|C1|C2",
  "emo": "one of: HOOK|CRAFT|AWE|TENSE|DRAMA|REVEAL|CALM|POWER|JOY|FEAR|EPIC",
  "motion_level": "one of: high|medium|low",
  "sub": {
    "actor": "main subject (person, animal, object)",
    "action": "what the subject is doing",
    "obj": "object involved (empty string if none)"
  },
  "camera_goal": "what this shot should make the viewer feel or understand",
  "anatomy_prefix": "short Kling anatomy/realism constraint for the subject, e.g. 'Hyperrealistic. Single beagle dog. Four legs, floppy ears, tricolor fur. Natural canine anatomy.' or 'Hyperrealistic. Single person. Two arms, two legs. Natural anatomy, realistic hands.' — match the actual subject type exactly"
}

Light codes: W1=warm golden, W2=amber sunset, G1=golden hour, N1=night neon, N2=moonlit, D1=dramatic rim, S1=soft window, S2=studio, C1=clinical, C2=industrial
Move codes: STATIC=locked, P1=push-in, P2=pull-back, D1=dolly right, D2=dolly left, O1=orbital CW, O2=orbital CCW, H1=handheld, T1=tilt-up, T2=tilt-down
PROMPT;

        $quiet = $this->option('json');
        if (!$quiet) $this->line("\n[DSL] Calling Claude haiku for article: \"{$article->title}\"...");
        $response = $claude->generate($prompt, 'haiku');

        $text = trim($response->text);
        // Strip markdown code fences if present
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);

        $dsl = json_decode($text, true);
        if (!is_array($dsl) || empty($dsl['cam'])) {
            $this->error("[DSL] Claude returned unparseable response:\n{$text}");
            return null;
        }

        if (!$quiet) $this->line("[DSL] Extracted: cam={$dsl['cam']} move={$dsl['move']} emo={$dsl['emo']} lens={$dsl['lens']} light={$dsl['light']}");
        if (!$quiet) $this->line("[DSL] Subject: {$dsl['sub']['actor']} / {$dsl['sub']['action']}");

        return $dsl;
    }
}
