<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Snapshot;

use App\Services\AI\FilmOS\Policy\PolicyDecision;

/**
 * Produces a canonical hash of PolicyDecision (or null if no policy was applied).
 *
 * Uses PolicyDecision::toCanonicalArray() which:
 *   - Excludes appliedPolicies / skippedPolicies (audit log, not decisions)
 *   - Sorts disabledProviders alphabetically
 *   - Sorts metadata keys (ksort) so insertion order never affects the hash
 * This ensures hash reflects DECISIONS only, not observational metadata.
 *
 * HashSerializer is injected so encoding flags match across all hash builders.
 */
final class PolicyHashBuilder
{
    public function __construct(
        private readonly HashSerializer $serializer = new JsonHashSerializer(),
    ) {}

    public function build(?PolicyDecision $policy): ?string
    {
        if ($policy === null) {
            return null;
        }

        return $this->serializer->sha256($policy->toCanonicalArray());
    }
}
