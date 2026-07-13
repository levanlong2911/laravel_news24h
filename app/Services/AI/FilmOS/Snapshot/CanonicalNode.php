<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Snapshot;

/**
 * Typed canonical representation of a graph node for deterministic hashing.
 *
 * Replaces the raw array returned by canonicalData() so that the hash contract
 * is enforced by the type system, not by convention.
 *
 *   id     — stable node identifier (never changes across runs)
 *   type   — node category (enum.value: 'fact', 'leaf', 'render', …)
 *   parent — optional parent node ID (null for root nodes)
 *   kind   — optional domain sub-classification for future layers
 *             Phase C: CapabilityNode → kind = capability type
 *             Phase D: EventNode      → kind = event category
 *   data   — optional extra structural fields that belong in the hash but don't
 *             fit id/type/parent/kind (e.g. GoalNode::maxShots).
 *             Keys must be sorted (ksort) by the caller for determinism.
 *             Never put runtime state here — only structural properties.
 */
final class CanonicalNode
{
    public function __construct(
        public readonly string  $id,
        public readonly string  $type,
        public readonly ?string $parent = null,
        public readonly ?string $kind   = null,
        public readonly array   $data   = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = ['id' => $this->id, 'type' => $this->type];
        if ($this->parent !== null) $out['parent'] = $this->parent;
        if ($this->kind   !== null) $out['kind']   = $this->kind;
        if (!empty($this->data)) {
            $out['data'] = CanonicalArray::deepSort($this->data);
        }
        return $out;
    }
}
