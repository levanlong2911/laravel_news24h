<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Policy\Actions;

use App\Services\AI\FilmOS\Policy\PolicyAction;
use App\Services\AI\FilmOS\Policy\PolicyDecision;

final class DisableProviderAction implements PolicyAction
{
    /** @param string[] $providers */
    public function __construct(private readonly array $providers) {}

    public function apply(PolicyDecision $decision): void
    {
        foreach ($this->providers as $provider) {
            if (!in_array($provider, $decision->disabledProviders, strict: true)) {
                $decision->disabledProviders[] = $provider;
            }
        }
    }

    public function describe(): string
    {
        return 'disable providers: ' . implode(', ', $this->providers);
    }
}
