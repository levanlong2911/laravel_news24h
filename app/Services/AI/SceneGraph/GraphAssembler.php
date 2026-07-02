<?php

namespace App\Services\AI\SceneGraph;

use App\DTOs\PipelineContext;
use App\DTOs\SceneDTO;
use App\Models\VideoProject;
use App\Services\AI\PromptAST\PromptBlockAssembler;
use App\Services\AI\PromptAST\PromptNormalizer;
use App\Services\AI\PromptAST\Serializers\KlingSerializer;
use App\Services\AI\PromptCompiler\Compiler;
use App\Services\AI\ProviderResolver;
use App\Services\AI\SceneGraph\ContinuityEngine;
use App\Services\AI\SceneGraph\SceneGraphBuilder;
use App\Services\AI\SceneGraph\SceneGraphValidator;
use App\Services\AI\ScenePlanner\ScenePlanner;
use Illuminate\Support\Facades\Log;

/**
 * Builds the raw SceneGraph JSON from SceneDTO[].
 *
 * Per-shot pipeline (left branch):
 *   ShotDTO → ProviderResolver → PromptCompiler → AssetResolver
 *
 * Per-scene pipeline (right branch, applied after shot compilation):
 *   assembled scenes → TimelineBuilder → annotated with start_ms/end_ms/sequence_id
 *
 * Computes estimated_cost and totals.
 * Does NOT validate against schema — SceneGraphCompiler owns validation.
 */
final class GraphAssembler
{
    public const GRAPH_VERSION = '1.0';

    public function __construct(
        private readonly Compiler            $compiler,
        private readonly ScenePlanner        $scenePlanner,
        private readonly SceneGraphBuilder   $sceneGraphBuilder,
        private readonly SceneGraphValidator $sceneGraphValidator,
    ) {}

    /**
     * @param  SceneDTO[] $scenes
     */
    public function assemble(array $scenes, VideoProject $project, PipelineContext $pipeline): array
    {
        $compiledScenes   = [];
        $costsByProvider  = [];
        $continuityEngine = new ContinuityEngine();

        foreach ($scenes as $scene) {
            $compiledShots = $this->compileShots($scene, $costsByProvider, $continuityEngine);

            $compiledScenes[] = [
                'scene_id'     => $scene->sceneId,
                'scene_number' => $scene->sceneNumber,
                'title'        => $scene->title,
                'emotion'      => $scene->emotion,
                'duration'     => $scene->duration,
                'shots'        => $compiledShots,
            ];
        }

        // Timeline stage (right branch)
        $compiledScenes = TimelineBuilder::annotate($compiledScenes);

        $totalShots      = (int) array_sum(array_map(fn ($s) => count($s['shots']), $compiledScenes));
        $totalDurationMs = TimelineBuilder::totalDurationMs($compiledScenes);
        $totalDurationSec = $totalDurationMs / 1000.0;

        return [
            'graph_version'    => self::GRAPH_VERSION,
            'project_id'       => (string) $project->id,
            'article_id'       => (string) $project->article_id,
            'theme'            => $project->theme  ?? '',
            'style'            => $project->style  ?? '',
            'duration'         => (int) ($project->duration ?? 15),
            'contract_version' => $pipeline->contractVersion,
            'planner_version'  => $pipeline->plannerVersion,
            'compiler_version' => $pipeline->compilerVersion,
            'workflow_version' => $pipeline->workflowVersion,
            'total_scenes'     => count($compiledScenes),
            'total_shots'      => $totalShots,
            'total_duration_ms'=> $totalDurationMs,
            'estimated_cost'   => ProviderPricing::buildSummary($costsByProvider, $totalDurationSec),
            'scenes'           => $compiledScenes,
        ];
    }

    /**
     * Compiles all shots in a scene. Mutates $costsByProvider by reference.
     *
     * @return array[]  Raw shot arrays (before timeline annotation)
     */
    private function compileShots(SceneDTO $scene, array &$costsByProvider, ContinuityEngine $continuity): array
    {
        $compiledShots = [];

        foreach ($scene->shots() as $shot) {
            $dsl = $shot->toArray();
            $dsl['scene_id']      = $scene->sceneId;
            $dsl['shot_order']    = $shot->shotOrder;
            $dsl['scene_title']   = $scene->title;
            $dsl['scene_emotion'] = $scene->emotion;

            // Continuity anchor (shots 2+ only) — record before ScenePlanner
            // so anchor extraction uses clean DSL, not enriched one.
            $continuity->record($scene->sceneId, $shot->shotOrder, $dsl);
            $anchor = $continuity->anchorFor($scene->sceneId, $shot->shotOrder);

            // Sprint 4 pipeline: plan → ScenePlanningResult → build → validate → ShotSceneGraph
            $planningResult = $this->scenePlanner->plan($dsl);
            $shotGraph      = $this->sceneGraphBuilder->build($planningResult);
            $validation     = $this->sceneGraphValidator->validate($shotGraph);
            // Validation errors are non-fatal: log them but continue rendering.
            // A future pipeline stage will surface these to the admin UI.
            if (!$validation->passed) {
                Log::warning('SceneGraph validation failed', [
                    'shot_id' => $shotGraph->shotId,
                    'errors'  => $validation->errors,
                ]);
            }

            // Reconstruct enriched DSL for Compiler (array-based until renderer migration).
            // Start from original $dsl — ScenePlanningResult no longer carries a raw dsl field (Sprint 5).
            $enrichedDsl                     = $dsl;
            $enrichedDsl['action_plan']      = $planningResult->action->toArray();
            $enrichedDsl['timeline']         = $planningResult->timeline->toArray();
            $enrichedDsl['physics']          = $planningResult->physics->toArray();
            $enrichedDsl['director']         = $planningResult->director->toArray();
            $enrichedDsl['composition']      = $planningResult->composition->toArray();
            $enrichedDsl['continuity_plan']  = $planningResult->continuity->toArray();
            $enrichedDsl['semantic_intent']  = $planningResult->semantic->toArray();

            $provider = ProviderResolver::resolveFromDsl($enrichedDsl);

            // Sprint 6: AST pipeline for Kling. Legacy compiler path for unimplemented providers.
            if ($provider === 'kling') {
                $ast    = PromptBlockAssembler::assemble($shotGraph);
                $ast    = (new PromptNormalizer())->normalize($ast);
                $prompt = (new KlingSerializer())->serialize($ast);
            } else {
                $prompt = $this->compiler->compile($enrichedDsl, $provider, null, $anchor);
            }

            $promptHash = hash('sha256', $prompt);
            $assetRef   = $enrichedDsl['asset_ref'] ?? [];
            $asset      = AssetResolver::resolve($assetRef, $provider, $promptHash);

            // Accumulate cost
            $costsByProvider[$provider] = ($costsByProvider[$provider] ?? 0.0)
                + ProviderPricing::estimateShot($provider);

            $compiledShots[] = [
                'shot_id'         => $shotGraph->shotId,
                'shot_order'      => $shot->shotOrder,
                'compiled_prompt' => $prompt,
                'hint'            => $provider,
                'dur'             => $shot->dur,
                'cinematic_dsl'   => $enrichedDsl,
                'scene_graph'     => $shotGraph->toArray(),
                'asset'           => $asset,
            ];
        }

        return $compiledShots;
    }
}
