<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Render;

final class ProviderProfile
{
    public function __construct(
        public readonly string $provider,
        public readonly float  $cost        = 0.5,
        public readonly float  $quality     = 0.5,
        public readonly float  $latency     = 0.5,
        public readonly float  $reliability = 0.5,
        public readonly array  $attributes  = [],
    ) {}
}
