<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Provider;

use App\Services\AI\FilmOS\ExecutionGraph\ExecutionEdge;
use App\Services\AI\FilmOS\ExecutionGraph\ExecutionGraph;
use App\Services\AI\FilmOS\ExecutionGraph\ExecutionNode;
use App\Services\AI\FilmOS\ExecutionGraph\ExecutionRuntime;
use App\Services\AI\FilmOS\ExecutionGraph\CheckpointStore;
use App\Services\AI\FilmOS\EventBus\EventBus;
use App\Services\AI\FilmOS\Intent\DirectorIntent;
use App\Services\AI\FilmOS\Kernel\Plugins\RenderPlugin;
use App\Services\AI\FilmOS\Snapshot\ExecutionLayerBuilder;

/**
 * Executes render tasks through MockKlingProvider via ExecutionRuntime.
 *
 * Architecture:
 *   ProviderHarness builds the ExecutionGraph (topology only),
 *   runs it through ExecutionRuntime (which maintains ExecutionRuntimeState[]),
 *   then delegates to ExecutionLayerBuilder which reads ONLY from
 *   ExecutionRuntimeState and CheckpointEntry[] — never from ExecutionNode.
 *
 * chain=true wires shots sequentially (F1→F2→F3→F4) to demonstrate
 * cascade SKIP when an early shot fails.
 */
final class ProviderHarness
{
    public function __construct(
        private readonly MockKlingProvider    $provider,
        private readonly CheckpointStore      $checkpoints,
        private readonly ?EventBus            $eventBus     = null,
        private readonly ExecutionLayerBuilder $layerBuilder = new ExecutionLayerBuilder(),
    ) {}

    /**
     * @param  string                         $executionId  unique ID for this run
     * @param  array<string, DirectorIntent>  $intents      subGoalId → DirectorIntent
     * @param  bool                           $chain        wire shots sequentially
     */
    public function run(
        string $executionId,
        array  $intents,
        bool   $chain = false,
    ): HarnessResult {
        $graph    = $this->buildExecutionGraph($executionId, $intents, $chain);
        $handlers = $this->buildHandlers($intents);
        $runtime  = new ExecutionRuntime($this->checkpoints, $this->eventBus);

        $result = $runtime->run($executionId, $graph, $handlers);

        // ExecutionLayerBuilder reads from states + checkpointLog — NOT from ExecutionNode
        $section = $this->layerBuilder->build(
            $result->graph,
            $result->states,
            $result->checkpointLog,
        );

        return new HarnessResult(
            graph:            $result->graph,
            executionSection: $section,
            metrics:          $result->metrics,
            states:           $result->states,
            checkpointLog:    $result->checkpointLog,
        );
    }

    // ── Private builders ──────────────────────────────────────────────────────

    private function buildExecutionGraph(
        string $executionId,
        array  $intents,
        bool   $chain,
    ): ExecutionGraph {
        $graph = new ExecutionGraph(
            executionId:  $executionId,
            productionId: $executionId,
            createdAt:    microtime(true),
        );

        $prevId = null;
        foreach ($intents as $subGoalId => $intent) {
            $nodeId = "render_{$subGoalId}";
            $graph->addNode(new ExecutionNode(
                id:          $nodeId,
                taskId:      $nodeId,       // planning-level identity — stable across replays
                executionId: $executionId,  // which run; excluded from canonical hash
                description: "Render: {$intent->shotId}",
            ));

            if ($chain && $prevId !== null) {
                $graph->addEdge(new ExecutionEdge($prevId, $nodeId));
            }
            $prevId = $nodeId;
        }

        return $graph;
    }

    /** @return array<string, callable>  taskId → handler */
    private function buildHandlers(array $intents): array
    {
        $handlers = [];
        foreach ($intents as $subGoalId => $intent) {
            $taskId   = "render_{$subGoalId}";
            $prompt   = RenderPlugin::buildPromptFromIntent($intent);
            $provider = $this->provider;

            $handlers[$taskId] = static fn() => $provider->render($taskId, $prompt);
        }
        return $handlers;
    }
}
