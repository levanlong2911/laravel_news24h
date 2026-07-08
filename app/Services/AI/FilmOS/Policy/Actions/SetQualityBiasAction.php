<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Policy\Actions;

use App\Services\AI\FilmOS\Policy\PolicyAction;
use App\Services\AI\FilmOS\Policy\PolicyDecision;

final class SetQualityBiasAction implements PolicyAction
{
    /** @param 'quality'|'cost'|'balanced' $bias */
    public function __construct(private readonly string $bias) {}

    public function apply(PolicyDecision $decision): void
    {
        $decision->qualityCostBias = $this->bias;
    }

    public function describe(): string
    {
        return "set qualityCostBias = {$this->bias}";
    }
}
