<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Planning;

interface PlanningPlugin
{
    public function name(): string;

    public function priority(): int;

    public function supports(PlanningContext $context): bool;

    public function plan(PlanningJob $job): PlanningContribution;
}
