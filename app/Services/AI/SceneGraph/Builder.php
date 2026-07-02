<?php

namespace App\Services\AI\SceneGraph;

use App\DTOs\PipelineContext;
use App\Models\VideoProject;
use App\Services\AI\PromptCompiler\Compiler;
use App\Services\AI\ProviderResolver;

/**
 * Assembles the SceneGraph from DB (video_scenes + video_shots) by applying
 * ProviderResolver + PromptCompiler to every shot.
 *
 * Called at media-job claim time — never stores compiled_prompt in DB.
 * Output validates against contracts/v1/SceneGraph.schema.json.
 */
final class Builder
{
    public function __construct(
        private readonly Compiler $compiler,
    ) {}

    public function build(VideoProject $project, PipelineContext $pipeline): array
    {
        $scenes = $project->scenes()->with('shots')->get();

        $sceneGraphScenes = [];

        foreach ($scenes as $scene) {
            $shots = [];

            foreach ($scene->shots()->orderBy('shot_number')->get() as $shot) {
                $dsl      = $shot->cinematic_dsl ?? [];
                $provider = ProviderResolver::resolveFromDsl($dsl);
                $prompt   = $this->compiler->compile($dsl, $provider);

                $shots[] = [
                    'shot_id'         => $shot->id,
                    'shot_order'      => $shot->shot_number ?? $dsl['shot_order'] ?? 1,
                    'compiled_prompt' => $prompt,
                    'hint'            => $provider,
                    'dur'             => (float) ($dsl['dur'] ?? $shot->estimated_duration ?? 2.0),
                    'cinematic_dsl'   => $dsl,
                    'asset'           => $this->buildAssetEntry($dsl),
                ];
            }

            $sceneGraphScenes[] = [
                'scene_id'     => $scene->id,
                'scene_number' => $scene->scene_number,
                'title'        => $scene->title,
                'emotion'      => $scene->emotion,
                'duration'     => (float) $scene->duration,
                'shots'        => $shots,
            ];
        }

        return [
            'project_id'       => $project->id,
            'article_id'       => (string) $project->article_id,
            'theme'            => $project->theme ?? '',
            'style'            => $project->style ?? '',
            'duration'         => (int) ($project->duration ?? 15),
            'contract_version' => $pipeline->contractVersion,
            'planner_version'  => $pipeline->plannerVersion,
            'compiler_version' => $pipeline->compilerVersion,
            'workflow_version' => $pipeline->workflowVersion,
            'scenes'           => $sceneGraphScenes,
        ];
    }

    private function buildAssetEntry(array $dsl): array
    {
        $assetRef = $dsl['asset_ref'] ?? null;
        if ($assetRef === null) {
            return [];
        }

        return [
            'id'        => $assetRef['id']        ?? '',
            'type'      => $assetRef['type']       ?? 'prop',
            'reuse'     => (bool) ($assetRef['reuse']  ?? false),
            'variation' => $assetRef['variation']  ?? '',
        ];
    }
}
