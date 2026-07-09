<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\DecisionDAG;

use App\Services\AI\FilmOS\Graph\GraphNode;
use App\Services\AI\FilmOS\Snapshot\CanonicalNode;
use App\Services\AI\FilmOS\Snapshot\HashableNode;

final class DAGNode extends GraphNode implements HashableNode
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

    public function canonicalNode(): CanonicalNode
    {
        return new CanonicalNode(id: $this->id, type: $this->type->value);
    }
}
