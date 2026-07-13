<?php

declare(strict_types=1);

namespace App\Console\Commands\FilmOS;

use App\Models\Benchmark\BmFixture;
use App\Models\Benchmark\BmPlanner;
use App\Models\Benchmark\BmPlannerOutput;
use App\Models\Benchmark\BmRender;
use App\Models\Benchmark\BmSession;
use App\Services\AI\FilmOS\Intent\IntentAssembler;
use App\Services\AI\FilmOS\Kernel\Plugins\RenderPlugin;
use App\Services\AI\FilmOS\Learning\StubPredictiveLearning;
use App\Services\AI\FilmOS\Meaning\ContextualMeaningResolver;
use App\Services\AI\FilmOS\Narrative\NarrativeStructureBuilder;
use App\Services\AI\FilmOS\Planning\Estimators\CostEstimator;
use App\Services\AI\FilmOS\Planning\Estimators\LatencyEstimator;
use App\Services\AI\FilmOS\Planning\GoalDecomposer;
use App\Services\AI\FilmOS\Planning\MultiObjectiveOptimizer;
use App\Services\AI\FilmOS\Planning\PlanObjectives;
use App\Services\AI\FilmOS\Planning\SequenceOptimizer;
use App\Services\AI\FilmOS\Planning\ShotSequencePlan;
use App\Services\AI\FilmOS\Planning\Strategies\CameraStrategy;
use App\Services\AI\FilmOS\Planning\Strategies\MotionStrategy;
use App\Services\AI\FilmOS\Planning\SubGoalPlanner;
use App\Services\AI\FilmOS\Testing\GoldenScenarioPipeline;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Register a completed production output.mp4 as a benchmark render entry.
 *
 * Workflow:
 *   1. Reads output.mp4 metadata via ffprobe
 *   2. Replays the planning pipeline to recover per-shot prompts (deterministic, no AI)
 *   3. Creates bm_session + bm_fixture (firstOrCreate)
 *   4. Creates bm_renders record with full metadata
 *   5. Copies output.mp4 to public/ for the annotation UI
 *   6. Saves per-shot prompts as bm_planner_outputs
 *   7. Prints the annotation URL
 *
 * Usage:
 *   php artisan filmos:register-production
 *   php artisan filmos:register-production --production-id=prod_20260710_100955 --domain=sports
 */
class RegisterProductionCommand extends Command
{
    protected $signature = 'filmos:register-production
                            {--production-id=prod_20260710_100955  : Production folder name under storage/app/productions/}
                            {--session-code=sprint-d3-sports        : bm_session code (created if absent)}
                            {--session-name=Sprint D.3 Sports       : Session display name (on create only)}
                            {--fixture=sports_golden_scenario        : bm_fixture slug (created if absent)}
                            {--prompt-version=sprint_d3_narrative   : Stored in bm_renders.prompt_version}
                            {--domain=sports                        : Domain used during production (for pipeline replay)}
                            {--git-commit=                          : Git commit hash (auto-detected if omitted)}';

    protected $description = 'Register a production output.mp4 into the benchmark system for annotation';

    public function handle(): int
    {
        $productionId  = (string) $this->option('production-id');
        $sessionCode   = (string) $this->option('session-code');
        $sessionName   = (string) $this->option('session-name');
        $fixtureSlug   = (string) $this->option('fixture');
        $promptVersion = (string) $this->option('prompt-version');
        $domain        = (string) $this->option('domain');
        $gitCommit     = $this->option('git-commit') ?: $this->resolveGitCommit();

        $outputDir = storage_path("app/productions/{$productionId}");
        $mp4Path   = "{$outputDir}/output.mp4";

        // ── Validate source ───────────────────────────────────────────────────
        if (! file_exists($mp4Path)) {
            $this->error("output.mp4 not found: {$mp4Path}");
            $this->line("Run: php artisan filmos:produce --domain={$domain}");
            return self::FAILURE;
        }

        $this->info("FilmOS Register Production — {$productionId}");
        $this->line('  source   : ' . $mp4Path . ' (' . round(filesize($mp4Path) / 1_048_576, 2) . ' MB)');
        $this->newLine();

        // ── Step 1: Video metadata ────────────────────────────────────────────
        $this->info('[1/6] Reading video metadata via ffprobe…');
        $meta = $this->probeVideo($mp4Path);
        if ($meta === null) {
            $this->error('ffprobe failed — is ffprobe installed and on PATH?');
            return self::FAILURE;
        }
        $this->line("  {$meta['width']}×{$meta['height']} @ {$meta['fps']}fps, {$meta['duration']}s");

        // ── Step 2: Replay planning pipeline for prompts ──────────────────────
        $this->info('[2/6] Replaying planning pipeline (no AI calls)…');
        $shotPrompts  = $this->replayForPrompts($domain, $productionId);
        $promptConcat = implode("\n\n---\n\n", array_values($shotPrompts));
        $charCount    = mb_strlen($promptConcat);
        $this->line('  ' . count($shotPrompts) . ' shots replanned, ' . $charCount . ' combined prompt chars');

        // ── Step 3: Session + fixture ─────────────────────────────────────────
        $this->info('[3/6] Resolving benchmark session and fixture…');

        $session = BmSession::firstOrCreate(
            ['code' => $sessionCode],
            [
                'name'        => $sessionName,
                'sprint'      => 'D.3',
                'description' => 'Sprint D.3 — First real sports video from FilmOS pipeline',
                'git_commit'  => $gitCommit,
            ]
        );
        $this->line("  session  : {$session->code} (id={$session->id})");

        $fixture = BmFixture::firstOrCreate(
            ['slug' => $fixtureSlug],
            [
                'name'           => 'Sports Golden Scenario',
                'scene_category' => 'sports',
            ]
        );
        $this->line("  fixture  : {$fixture->slug} (id={$fixture->id})");

        // ── Step 4: bm_renders record ─────────────────────────────────────────
        $this->info('[4/6] Creating bm_renders record…');

        $uuid   = (string) Str::uuid();
        $render = BmRender::create([
            'uuid'             => $uuid,
            'session_id'       => $session->id,
            'fixture_id'       => $fixture->id,
            'model'            => 'kling-v1.6-standard',
            'resolution'       => "{$meta['width']}x{$meta['height']}",
            'duration_seconds' => (float) $meta['duration'],
            'fps'              => (int) round((float) $meta['fps']),
            'seed'             => 0,
            'char_count'       => $charCount,
            'prompt_version'   => $promptVersion,
            'artifact_path'    => "renders/{$sessionCode}/{$uuid}",
            'git_commit'       => $gitCommit,
            'rendered_at'      => now(),
        ]);
        $this->line("  render   : id={$render->id}, uuid={$render->uuid}");

        // ── Step 5: Publish video for annotation UI ───────────────────────────
        $this->info('[5/6] Publishing video to public/renders/…');

        $publicDir = public_path("renders/{$sessionCode}/{$uuid}");
        if (! is_dir($publicDir)) {
            mkdir($publicDir, 0755, recursive: true);
        }
        $targetMp4 = "{$publicDir}/video.mp4";
        copy($mp4Path, $targetMp4);
        $this->line("  copied   : public/renders/{$sessionCode}/{$uuid}/video.mp4");

        // ── Step 6: planner_outputs ───────────────────────────────────────────
        $this->info('[6/6] Saving planner outputs…');

        $plannerFile = 'Services/AI/FilmOS/Kernel/Plugins/RenderPlugin.php';
        $plannerAbs  = app_path($plannerFile);
        $planner     = BmPlanner::firstOrCreate(
            ['name' => 'FilmOS/RenderPlugin'],
            [
                'file_path'   => $plannerFile,
                'fingerprint' => file_exists($plannerAbs) ? hash_file('sha256', $plannerAbs) : null,
            ]
        );

        foreach ($shotPrompts as $beat => $promptText) {
            BmPlannerOutput::create([
                'render_id'  => $render->id,
                'planner_id' => $planner->id,
                'beat'       => $beat,
                'raw_text'   => $promptText,
            ]);
        }
        $this->line('  saved    : ' . count($shotPrompts) . ' planner outputs');

        // ── Summary ───────────────────────────────────────────────────────────
        $annotateUrl = url("benchmark/annotate/{$uuid}");
        $this->newLine();
        $this->info('══════════════════════════════════════════════════');
        $this->info('  REGISTERED — Sprint D.3 benchmark entry');
        $this->info('══════════════════════════════════════════════════');
        $this->table(['Field', 'Value'], [
            ['render_id',      $render->id],
            ['uuid',           $uuid],
            ['session',        $sessionCode],
            ['fixture',        $fixtureSlug],
            ['prompt_version', $promptVersion],
            ['char_count',     $charCount],
            ['duration',       $meta['duration'] . 's'],
            ['resolution',     $meta['width'] . '×' . $meta['height']],
            ['fps',            $meta['fps']],
            ['video',          "public/renders/{$sessionCode}/{$uuid}/video.mp4"],
        ]);
        $this->newLine();
        $this->info("  ANNOTATE → {$annotateUrl}");
        $this->newLine();
        $this->line('Next steps:');
        $this->line("  1. Open: {$annotateUrl}");
        $this->line('  2. Watch the video, score all dimensions (identity, camera, physics, etc.)');
        $this->line('  3. Compare overall score vs Sprint B baseline (render_id=11, overall=5)');
        $this->line('     ≤5 = no improvement; 6-7 = Sprint D pass; ≥8 = strong confirmation');

        return self::SUCCESS;
    }

    // ── Pipeline replay ───────────────────────────────────────────────────────

    /**
     * Re-run the deterministic planning pipeline to recover per-shot Kling prompts.
     * Mirrors ProduceCommand steps 1-3 exactly (golden scenario path, no AI calls).
     *
     * @return array<string, string>  beat → prompt text (e.g. 'hook' → 'Hyperrealistic...')
     */
    private function replayForPrompts(string $domain, string $productionId): array
    {
        $facts = GoldenScenarioPipeline::facts();

        $meaning   = (new ContextualMeaningResolver())->resolve($facts, $domain);
        $narrative = (new NarrativeStructureBuilder())->build($meaning);
        $goalGraph = (new GoalDecomposer())->decompose($narrative);
        $leaves    = $goalGraph->leaves();

        $subGoalPlanner = new SubGoalPlanner([new CameraStrategy(), new MotionStrategy()]);
        $sequenceOpt    = new SequenceOptimizer();

        $unordered = [];
        foreach ($leaves as $leaf) {
            $unordered[] = $subGoalPlanner->plan($leaf, $meaning, []);
        }
        $ordered = $sequenceOpt->optimize($unordered, $goalGraph);

        $optimizer = new MultiObjectiveOptimizer(
            new CostEstimator(),
            new LatencyEstimator(),
            new StubPredictiveLearning(),
        );
        $rawPlan = new ShotSequencePlan("plan_{$productionId}", $goalGraph, $ordered, 0.88);
        $plan    = $optimizer->optimize($rawPlan, PlanObjectives::breakingNews(), ['domain' => $domain]);

        $assembler   = new IntentAssembler();
        $shotPrompts = [];

        foreach ($plan->shots as $shot) {
            $intent  = $assembler->assemble($productionId, "dag_{$productionId}", $shot, $meaning, $facts);
            $prompt  = RenderPlugin::buildPromptFromIntent($intent);
            $beat    = ltrim(str_replace('shot_', '', $shot->subGoalId), '_');
            $shotPrompts[$beat] = $prompt;
        }

        return $shotPrompts;
    }

    // ── ffprobe ───────────────────────────────────────────────────────────────

    /** @return array{width:int,height:int,fps:float,duration:float}|null */
    private function probeVideo(string $path): ?array
    {
        $ffprobe = config('filmos.ffprobe_path', 'ffprobe');
        $args    = [
            $ffprobe,
            '-v', 'quiet',
            '-print_format', 'json',
            '-show_streams',
            '-show_format',
            $path,
        ];

        $cmd    = implode(' ', array_map('escapeshellarg', $args));
        $output = shell_exec($cmd);
        if ($output === null || $output === '') {
            return null;
        }

        $data = json_decode($output, true);
        if (! is_array($data)) {
            return null;
        }

        $video = null;
        foreach ($data['streams'] ?? [] as $stream) {
            if (($stream['codec_type'] ?? '') === 'video') {
                $video = $stream;
                break;
            }
        }

        if ($video === null) {
            return null;
        }

        [$num, $den] = explode('/', $video['r_frame_rate'] ?? '24/1') + [0 => 24, 1 => 1];
        $fps      = (int) $den > 0 ? round((float) $num / (float) $den, 3) : 24.0;
        $duration = (float) ($data['format']['duration'] ?? $video['duration'] ?? 0);

        return [
            'width'    => (int) ($video['width']  ?? 1920),
            'height'   => (int) ($video['height'] ?? 1080),
            'fps'      => $fps,
            'duration' => round($duration, 3),
        ];
    }

    // ── Utilities ─────────────────────────────────────────────────────────────

    private function resolveGitCommit(): string
    {
        $hash = shell_exec('git rev-parse --short HEAD 2>/dev/null');
        return $hash !== null ? trim($hash) : 'unknown';
    }
}
