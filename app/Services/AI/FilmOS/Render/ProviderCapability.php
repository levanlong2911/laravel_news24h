<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Render;

final class ProviderCapability
{
    public function __construct(
        public readonly int   $maxDurationSeconds,
        public readonly bool  $supportsVideo   = true,
        public readonly bool  $supportsImage   = false,
        public readonly bool  $supportsAudio   = false,
        public readonly array $attributes      = [],
    ) {}
}
