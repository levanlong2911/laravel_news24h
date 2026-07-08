<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Policy\Actions;

use App\Services\AI\FilmOS\Policy\PolicyAction;
use App\Services\AI\FilmOS\Policy\PolicyDecision;

final class RequireReviewersAction implements PolicyAction
{
    public function __construct(private readonly int $count) {}

    public function apply(PolicyDecision $decision): void
    {
        // Always take the strictest requirement.
        $decision->requiredReviewers = max($decision->requiredReviewers, $this->count);
    }

    public function describe(): string
    {
        return "require {$this->count} reviewers";
    }
}
