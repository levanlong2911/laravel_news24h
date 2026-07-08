<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Policy;

/**
 * A named, prioritised rule: condition → action.
 *
 * Policies are pure data — no logic lives here.
 * Logic lives in the condition and action implementations.
 */
final class Policy
{
    public function __construct(
        public readonly string          $name,
        public readonly PolicyCondition $condition,
        public readonly PolicyAction    $action,
        /** Higher priority = evaluated and applied first. */
        public readonly int             $priority = 100,
        public readonly string          $description = '',
    ) {}
}
