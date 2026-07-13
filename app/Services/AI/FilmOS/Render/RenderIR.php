<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Render;

final class RenderIR
{
    public function __construct(
        public readonly string $traceId,
        public readonly int    $version,
        public readonly string $shotId,
        public readonly int    $durationSeconds,
        public readonly array  $renderInstructions,
        public readonly array  $constraints,
        public readonly array  $metadata,
        public readonly array  $attributes = [],
    ) {}
}
