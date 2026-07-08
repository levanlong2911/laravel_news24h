<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\DecisionDAG;

use App\Services\AI\FilmOS\Graph\Graph;
use App\Services\AI\FilmOS\Graph\GraphNode;
use App\Services\AI\FilmOS\Graph\GraphTraversal;

/**
 * Causal trace graph — records WHY each output was produced.
 * Every node corresponds to one DAGRuntime.execute() call.
 *
 * @extends Graph<DAGNode, DAGEdge>
 */
final class DecisionDAG extends Graph
{
    public function __construct(
        public readonly string $productionId,
    ) {}

    /** @return DAGNode[] nodes of a given type */
    public function nodesOfType(DAGNodeType $type): array
    {
        return array_values(array_filter(
            $this->nodes(),
            fn(DAGNode $n) => $n->type === $type,
        ));
    }

    /**
     * Trace from a node back to its FACT root(s).
     * Delegates to the Graph Kernel's traceBack — stop condition is DAGNode::isRoot().
     * @return string[] chain of node IDs from target to source FACT
     */
    public function traceToFacts(string $nodeId): array
    {
        return GraphTraversal::traceBack(
            $this,
            $nodeId,
            fn(GraphNode $n) => $n->isRoot(),
        );
    }

    /**
     * Human-readable explanation of why a node (typically RENDER) was produced.
     */
    public function explain(string $nodeId): string
    {
        $chain = $this->traceToFacts($nodeId);
        if (empty($chain)) {
            return "Node {$nodeId} not found.";
        }

        $lines = [];
        foreach ($chain as $i => $id) {
            /** @var DAGNode $n */
            $n       = $this->node($id);
            $indent  = str_repeat('  ', $i);
            $arrow   = $i === 0 ? '●' : '↑';
            $lines[] = "{$indent}{$arrow} [{$n->type->value}:{$id}] {$n->rationale} (conf={$n->confidence})";
        }

        return implode("\n", $lines);
    }
}
