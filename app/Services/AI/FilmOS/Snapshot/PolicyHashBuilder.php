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
 * This ensures hash reflects DECISIONS only, not observational metadata.
 */
final class PolicyHashBuilder
{
    public function build(?PolicyDecision $policy): ?string
    {
        if ($policy === null) {
            return null;
        }

        return hash('sha256', json_encode(
            $policy->toCanonicalArray(),
            JSON_THROW_ON_ERROR,
        ));
    }
}
