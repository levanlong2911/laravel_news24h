<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Render;

use App\Services\AI\FilmOS\Prompt\NodeWeightProvider;

final class ProviderProfileWeightAdapter implements NodeWeightProvider
{
    public function __construct(private readonly ProviderProfile $profile) {}

    public function weightFor(string $nodeId): float
    {
        return match ($nodeId) {
            'camera', 'physics', 'style' => $this->profile->quality,
            'lighting'                   => $this->profile->quality * 0.9,
            default                      => 1.0,
        };
    }
}
