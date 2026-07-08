<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\DecisionDAG;

use Closure;

/**
 * Every operation that produces meaningful output runs through DAGRuntime.execute().
 * The DAG node IS the operation result — no dual-write, no separate logging.
 * This enforces Invariant 3 from ADR-016.
 */
final class DAGRuntime
{
    private readonly DecisionDAG $dag;

    public function __construct(string $productionId)
    {
        $this->dag = new DecisionDAG($productionId);
    }

    /**
     * Execute an operation, record it as a DAG node, and return the result.
     *
     * @param  string      $nodeId     unique node identifier
     * @param  DAGNodeType $type       semantic type of this operation
     * @param  Closure     $operation  () => mixed
     * @param  string      $rationale  why this operation was run
     * @param  string[]    $parentIds  IDs of nodes this depends on
     * @return mixed the operation output
     */
    public function execute(
        string      $nodeId,
        DAGNodeType $type,
        Closure     $operation,
        string      $rationale  = '',
        array       $parentIds  = [],
        float       $confidence = 1.0,
    ): mixed {
        $output = $operation();

        $node = new DAGNode(
            id:         $nodeId,
            type:       $type,
            payload:    $output,
            confidence: $confidence,
            rationale:  $rationale,
        );
        $this->dag->addNode($node);

        foreach ($parentIds as $parentId) {
            $this->dag->addEdge(new DAGEdge($parentId, $nodeId));
        }

        return $output;
    }

    public function toDecisionDAG(): DecisionDAG
    {
        return $this->dag;
    }

    public function explain(string $nodeId): string
    {
        return $this->dag->explain($nodeId);
    }
}
