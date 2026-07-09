<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Snapshot;

/**
 * A graph edge that can produce a typed canonical representation for hashing.
 *
 * Implementors must return ONLY structural fields:
 *   INCLUDE:  from, to, rel enum value
 *   EXCLUDE:  weight, latency, timestamps, metadata
 */
interface HashableEdge extends GraphHashable
{
    public function canonicalEdge(): CanonicalEdge;
}
