<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Policy;

/**
 * Mutates a PolicyDecision when the associated condition is true.
 *
 * Actions are intentionally stateless — all state lives in PolicyDecision.
 */
interface PolicyAction
{
    public function apply(PolicyDecision $decision): void;

    /** Human-readable description for audit logs. */
    public function describe(): string;
}
