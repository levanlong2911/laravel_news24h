<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Planning;

final class PlanningContext
{
    public function __construct(
        public readonly string $goalId,
        public readonly string $beat,
        public readonly string $subject,
        public readonly string $action,
        public readonly string $environment,
        public readonly string $domain,
        public readonly array  $attributes = [],
    ) {}
}
