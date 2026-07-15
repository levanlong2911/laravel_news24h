<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompting\Adapter;

/**
 * Resolves the PromptRenderer for a provider. Consumers ask for a ProviderId
 * and never learn how the renderer is constructed — adding Veo/Runway is a
 * registration, not a change to any command or pipeline.
 */
interface PromptRendererRegistry
{
    public function get(ProviderId $provider): PromptRenderer;

    public function has(ProviderId $provider): bool;
}
