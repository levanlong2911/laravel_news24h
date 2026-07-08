<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Policy\Actions;

use App\Services\AI\FilmOS\Policy\PolicyAction;
use App\Services\AI\FilmOS\Policy\PolicyDecision;

final class SetPreferredProviderAction implements PolicyAction
{
    public function __construct(private readonly string $provider) {}

    public function apply(PolicyDecision $decision): void
    {
        $decision->preferredProvider = $this->provider;
    }

    public function describe(): string
    {
        return "set preferredProvider = {$this->provider}";
    }
}
