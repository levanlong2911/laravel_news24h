<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Snapshot;

/**
 * A graph node that can produce a typed canonical representation for hashing.
 *
 * Implementors must return ONLY structural fields — never runtime state:
 *   INCLUDE:  id, type enum value, optional parent/kind
 *   EXCLUDE:  confidence, status, rationale, timestamps, retry counts
 */
interface HashableNode extends GraphHashable
{
    public function canonicalNode(): CanonicalNode;
}
