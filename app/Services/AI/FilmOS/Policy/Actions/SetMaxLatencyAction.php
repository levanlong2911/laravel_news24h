<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Policy\Actions;

use App\Services\AI\FilmOS\Policy\PolicyAction;
use App\Services\AI\FilmOS\Policy\PolicyDecision;

final class SetMaxLatencyAction implements PolicyAction
{
    public function __construct(private readonly float $maxLatencyMs) {}

    public function apply(PolicyDecision $decision): void
    {
        $decision->maxLatencyMs = $this->maxLatencyMs;
    }

    public function describe(): string
    {
        return "set maxLatencyMs = {$this->maxLatencyMs}";
    }
}
