<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\DecisionDAG;

use App\Services\AI\FilmOS\Graph\GraphNode;

final class DAGNode extends GraphNode
{
    public function __construct(
        string              $id,
        public readonly DAGNodeType $type,
        public readonly mixed       $payload,
        public readonly float       $confidence,
        public readonly string      $rationale = '',
    ) {
        parent::__construct($id);
    }

    /** FACT nodes are roots — they have no parent in the DAG. */
    public function isRoot(): bool
    {
        return $this->type === DAGNodeType::FACT;
    }

    public function label(): string
    {
        return "[{$this->type->value}] {$this->id} conf={$this->confidence}";
    }
}
