<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Snapshot;

/**
 * Typed canonical representation of a graph edge for deterministic hashing.
 *
 *   from — source node ID
 *   to   — target node ID
 *   rel  — relation type (enum.value: 'caused', 'requires', 'supports', …)
 */
final class CanonicalEdge
{
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly string $rel,
    ) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        return ['from' => $this->from, 'to' => $this->to, 'rel' => $this->rel];
    }
}
