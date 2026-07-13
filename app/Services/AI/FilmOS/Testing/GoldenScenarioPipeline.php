<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Testing;

use App\Services\AI\FilmOS\DecisionDAG\DAGNodeType;
use App\Services\AI\FilmOS\DecisionDAG\DAGRuntime;
use App\Services\AI\FilmOS\Evaluation\Plugins\EvaluationPlugin;
use App\Services\AI\FilmOS\Intent\IntentAssembler;
use App\Services\AI\FilmOS\Kernel\Plugins\RenderPlugin;
use App\Services\AI\FilmOS\Learning\StubPredictiveLearning;
use App\Services\AI\FilmOS\Meaning\ContextualMeaningResolver;
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
use App\Services\AI\FilmOS\Snapshot\ArtifactLayerBuilder;
use App\Services\AI\FilmOS\Snapshot\DeterminismManifest;
use App\Services\AI\FilmOS\Snapshot\ExecutionSnapshot;
use App\Services\AI\FilmOS\Snapshot\PlanningSnapshotBuilder;
use App\Services\AI\FilmOS\Snapshot\SnapshotComposer;
use App\Services\AI\FilmOS\Snapshot\TaskDescriptor;

/**
 * Canonical golden scenario pipeline — single source of truth used by:
 *   - filmos:verify-determinism (run N times, compare hashes)
 *   - filmos:replay-run (compare original vs replay)
 *   - Unit tests (verify identical runs produce identical snapshots)
 *
 * The four hardcoded facts produce a fixed-input scenario whose output
 * is fully deterministic — any hash divergence between two runs indicates
 * a non-determinism bug in the pipeline.
 *
 * Sections included in each snapshot:
 *   PlanningSection  (Phase A)
 *   ArtifactSection  (Phase F) — sha256 of each mock video URL, sorted by task ID
 */
final class GoldenScenarioPipeline
{
    private const DOMAIN = 'travel_warning';

    /**
     * Fixed 4-fact scenario.
     * Values are intentionally stable — do NOT change them without bumping
     * DeterminismManifest::$worldVersion and documenting the breaking change.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function facts(): array
    {
        return [
            [
                'id'               => 'F1',
                'text'             => 'Cockroach infestation found in multiple guest rooms',
                'category'         => 'EVIDENCE',
                'visual_relevance' => 'HIGH',
                'confidence'       => 0.95,
                'visual_hint'      => 'cockroach on hotel bedsheet',
            ],
            [
                'id'               => 'F2',
                'text'             => 'Health department issued formal warning',
                'category'         => 'RESULT',
                'visual_relevance' => 'MEDIUM',
                'confidence'       => 0.92,
                'visual_hint'      => 'official health department notice document',
            ],
            [
                'id'               => 'F3',
                'text'             => 'Travelers advised to avoid property',
                'category'         => 'RESULT',
                'visual_relevance' => 'MEDIUM',
                'confidence'       => 0.88,
                'visual_hint'      => 'travel advisory overlay text',
            ],
            [
                'id'               => 'F4',
                'text'             => 'Sunset Palace Resort, Bali — 3-star rated',
                'category'         => 'CONTEXT',
                'visual_relevance' => 'HIGH',
                'confidence'       => 0.90,
                'visual_hint'      => 'hotel exterior, Bali architecture',
            ],
        ];
    }

    /**
     * Run the full golden scenario pipeline and return an ExecutionSnapshot.
     *
     * The $runId is used as the productionId for DAG node IDs only.
     * It does NOT affect the canonical hash — executionId is excluded from
     * canonicalHash() by design, so two runs with different IDs still produce
     * identical hashes when the pipeline is deterministic.
     */
    public function run(string $runId): ExecutionSnapshot
    {
        $facts  = self::facts();
        $domain = self::DOMAIN;

        // ── L2: MeaningGraph ──────────────────────────────────────────────────
        $resolver = new ContextualMeaningResolver();
        $meaning  = $resolver->resolve($facts, $domain);

        // ── L3: GoalGraph + Planning ──────────────────────────────────────────
        $decomposer = new GoalDecomposer();
        $goalGraph  = $decomposer->decompose($meaning, $domain);

        $subGoalPlanner = new SubGoalPlanner([new CameraStrategy(), new MotionStrategy()]);
        $sequenceOpt    = new SequenceOptimizer();

        $unordered = [];
        foreach ($goalGraph->leaves() as $leaf) {
            $unordered[] = $subGoalPlanner->plan($leaf, $meaning, []);
        }
        $ordered = $sequenceOpt->optimize($unordered, $goalGraph);

        $learning   = new StubPredictiveLearning();
        $optimizer  = new MultiObjectiveOptimizer(new CostEstimator(), new LatencyEstimator(), $learning);
        $objectives = PlanObjectives::breakingNews();
        $rawPlan    = new ShotSequencePlan("plan_{$runId}", $goalGraph, $ordered, 0.88);
        $plan       = $optimizer->optimize($rawPlan, $objectives, ['domain' => $domain]);

        // ── DAGRuntime: full trace (L1 → L7) ─────────────────────────────────
        $runtime = new DAGRuntime($runId);
        foreach ($facts as $fact) {
            $runtime->execute($fact['id'], DAGNodeType::FACT,
                fn() => $fact, $fact['text'], [], $fact['confidence']);
        }
        $runtime->execute('meaning_graph', DAGNodeType::MEANING,
            fn() => $meaning, "CausalMeaningGraph: domain={$domain}",
            array_column($facts, 'id'), 0.91);
        $runtime->execute('strategy_plan', DAGNodeType::PLAN,
            fn() => $plan, 'MultiObjectiveOptimizer', ['meaning_graph'], $rawPlan->goalConfidence);

        // ── L4: INTENT nodes ──────────────────────────────────────────────────
        $assembler   = new IntentAssembler();
        $intents     = [];
        $descriptors = [];

        foreach ($ordered as $shot) {
            $intent = $runtime->execute(
                "intent_{$shot->subGoalId}", DAGNodeType::INTENT,
                fn() => $assembler->assemble($runId, "dag_{$runId}", $shot, $meaning, $facts),
                "DirectorIntent: {$shot->subGoalId}",
                ['strategy_plan'],
                0.91,
            );
            $intents[$shot->subGoalId] = $intent;
            $descriptors[] = new TaskDescriptor(
                id:         "render_{$shot->subGoalId}",
                type:       'render',
                priority:   $intent->evaluation->priority->value,
                dependsOn:  ["intent_{$shot->subGoalId}"],
                deadlineMs: 15000.0,
            );
        }

        // ── L6: mock RENDER nodes ─────────────────────────────────────────────
        $renderResults = [];
        foreach ($intents as $subGoalId => $intent) {
            $taskId     = "render_{$subGoalId}";
            $mockOutput = [
                'shotId'   => $intent->shotId,
                'videoUrl' => "https://mock.kling.ai/{$subGoalId}.mp4",
                'taskId'   => "mock_{$subGoalId}",
                'prompt'   => RenderPlugin::buildPromptFromIntent($intent),
            ];
            $runtime->execute(
                $taskId, DAGNodeType::RENDER,
                fn() => $mockOutput,
                "Rendered: {$intent->shotId} (golden scenario — dry-run)",
                ["intent_{$subGoalId}"],
                0.86,
            );
            $renderResults[$taskId] = $mockOutput;
        }

        // ── L7: REVIEW nodes ──────────────────────────────────────────────────
        $evaluator = new EvaluationPlugin();
        foreach ($intents as $subGoalId => $intent) {
            $runtime->execute(
                "review_{$subGoalId}", DAGNodeType::REVIEW,
                fn() => $evaluator->review($intent, $renderResults["render_{$subGoalId}"]),
                "MultiAgentReview for {$intent->shotId}",
                ["render_{$subGoalId}"],
                0.89,
            );
        }

        // ── Snapshot assembly ─────────────────────────────────────────────────
        $dag          = $runtime->toDecisionDAG();
        $worldVersion = hash('sha256', json_encode($facts, JSON_THROW_ON_ERROR));
        $manifest     = DeterminismManifest::current($worldVersion);

        $planningSection  = (new PlanningSnapshotBuilder())->build($dag, $goalGraph, $plan, $intents, $descriptors);
        $artifactSection  = (new ArtifactLayerBuilder())->build($renderResults);

        return (new SnapshotComposer())->compose($runId, $manifest, $planningSection, $artifactSection);
    }
}
