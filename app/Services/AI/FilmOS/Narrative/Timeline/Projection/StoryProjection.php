<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Timeline\Projection;

final class StoryProjection
{
    /**
     * @param array<int, array{shotId: string, ordinal: int, goalType: string, description: string}> $shots
     */
    public function __construct(
        public readonly array $shots = [],
    ) {}
}
