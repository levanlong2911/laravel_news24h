<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Policy;

/**
 * Evaluates a PolicyContext and returns true when the policy should fire.
 */
interface PolicyCondition
{
    public function evaluate(PolicyContext $context): bool;

    /** Human-readable description for audit logs. */
    public function describe(): string;
}
