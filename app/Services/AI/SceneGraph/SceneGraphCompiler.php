<?php

namespace App\Services\AI\SceneGraph;

use App\DTOs\PipelineContext;
use App\DTOs\SceneDTO;
use App\Models\VideoProject;
use App\Services\AI\PlanningValidator;

/**
 * Orchestrator: SceneDTO[] → validated SceneGraph array.
 *
 * Delegates JSON assembly to GraphAssembler (which calls ProviderResolver,
 * PromptCompiler, AssetResolver, TimelineBuilder). This class owns only
 * the compile lifecycle: assemble → validate → return.
 *
 * Python API serves the returned array directly as JSON.
 * Rule 1: no provider names visible in this class.
 */
final class SceneGraphCompiler
{
    public function __construct(
        private readonly GraphAssembler  $assembler,
        private readonly PlanningValidator $validator,
    ) {}

    /**
     * @param  SceneDTO[] $scenes  Output from SceneShotPlanner
     * @return array               Validated SceneGraph (matches SceneGraph.schema.json)
     * @throws \RuntimeException   If the compiled graph fails contract validation
     */
    public function compile(array $scenes, VideoProject $project, PipelineContext $pipeline): array
    {
        $graph = $this->assembler->assemble($scenes, $project, $pipeline);

        $result = $this->validator->validate($graph, 'SceneGraph');
        if (!$result->passed) {
            $fields = implode(', ', array_column($result->errors, 'field'));
            throw new \RuntimeException(
                "SceneGraphCompiler: compiled graph failed contract validation — {$fields}"
            );
        }

        return $graph;
    }
}
