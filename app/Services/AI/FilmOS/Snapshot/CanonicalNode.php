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
 */
final class CanonicalNode
{
    public function __construct(
        public readonly string  $id,
        public readonly string  $type,
        public readonly ?string $parent = null,
        public readonly ?string $kind   = null,
    ) {}

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        $data = ['id' => $this->id, 'type' => $this->type];
        if ($this->parent !== null) $data['parent'] = $this->parent;
        if ($this->kind   !== null) $data['kind']   = $this->kind;
        return $data;
    }
}
