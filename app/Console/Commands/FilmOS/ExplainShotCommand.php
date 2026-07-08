<?php

declare(strict_types=1);

namespace App\Console\Commands\FilmOS;

use App\Services\AI\FilmOS\DecisionDAG\DAGNodeType;
use App\Services\AI\FilmOS\DecisionDAG\DecisionDAG;
use App\Services\AI\FilmOS\Graph\GraphNode;
use App\Services\AI\FilmOS\Graph\GraphTraversal;
use Illuminate\Console\Command;

/**
 * Trace a rendered shot back to its source FactNodes in the DecisionDAG.
 * Validates Invariant 5 from ADR-016.
 *
 * Usage:
 *   php artisan filmos:explain-shot {productionId} {shotId}
 *   php artisan filmos:explain-shot prod_golden_20260708_120000 shot_002_cockroach_closeup
 */
class ExplainShotCommand extends Command
{
    protected $signature = 'filmos:explain-shot
                            {productionId : Production ID returned by filmos:run-golden-scenario}
                            {shotId       : Shot ID to trace (e.g. shot_002_cockroach_closeup)}';

    protected $description = 'Trace a rendered shot back to its source facts via the DecisionDAG (Invariant 5)';

    public function handle(): int
    {
        $productionId = $this->argument('productionId');
        $shotId       = $this->argument('shotId');

        /** @var DecisionDAG|null $dag */
        $cached = cache()->get("filmos_dag_{$productionId}");
        if (!$cached) {
            $this->error("No DAG found for production: {$productionId}");
            $this->line("Run filmos:run-golden-scenario first.");
            return self::FAILURE;
        }

        $dag = unserialize($cached);

        // RENDER node ID uses the subGoalId part (e.g. render_cockroach_closeup)
        $subGoalId  = preg_replace('/^shot_\d+_/', '', $shotId);
        $renderNode = "render_{$subGoalId}";

        $node = $dag->node($renderNode);
        if (!$node) {
            // Try by shotId directly
            $node = $dag->node($shotId);
            if (!$node) {
                $this->error("Node not found: {$renderNode}");
                $this->line("Available RENDER nodes:");
                foreach ($dag->nodesOfType(DAGNodeType::RENDER) as $n) {
                    $this->line("  - {$n->id}");
                }
                return self::FAILURE;
            }
            $renderNode = $shotId;
        }

        $this->info("Trace-back for: {$shotId}");
        $this->info("Production:     {$productionId}");
        $this->newLine();

        $chain = GraphTraversal::traceBack($dag, $renderNode, fn(GraphNode $n) => $n->isRoot());

        if (empty($chain)) {
            $this->error("Could not build trace — possible broken DAG edge.");
            return self::FAILURE;
        }

        $this->line("Chain (" . count($chain) . " nodes):");
        foreach ($chain as $i => $nodeId) {
            $n      = $dag->node($nodeId);
            $indent = str_repeat('  ', $i);
            $arrow  = $i > 0 ? '↑ ' : '● ';
            $this->line("{$indent}{$arrow}[{$n->type->value}] {$nodeId}");
            $this->line("{$indent}    rationale:  {$n->rationale}");
            $this->line("{$indent}    confidence: {$n->confidence}");

            if ($n->type === DAGNodeType::FACT) {
                $this->newLine();
                $this->info("Source fact reached. Invariant 5: PASS ✓");
                return self::SUCCESS;
            }
        }

        $this->newLine();
        $lastNode = $dag->node(end($chain));
        if ($lastNode && $lastNode->type === DAGNodeType::FACT) {
            $this->info("Source fact reached. Invariant 5: PASS ✓");
        } else {
            $this->warn("Trace did not reach a FACT node. Invariant 5: FAIL ✗");
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
