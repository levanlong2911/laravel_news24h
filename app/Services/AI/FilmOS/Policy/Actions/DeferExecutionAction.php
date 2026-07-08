<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Policy\Actions;

use App\Services\AI\FilmOS\Policy\PolicyAction;
use App\Services\AI\FilmOS\Policy\PolicyDecision;

final class DeferExecutionAction implements PolicyAction
{
    public function __construct(private readonly float $deferForMs = 0.0) {}

    public function apply(PolicyDecision $decision): void
    {
        $decision->deferExecution = true;
        $decision->deferForMs     = $this->deferForMs;
    }

    public function describe(): string
    {
        return "defer execution for {$this->deferForMs}ms";
    }
}
