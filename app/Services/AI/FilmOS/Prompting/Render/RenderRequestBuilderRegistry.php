<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompting\Render;

use App\Services\AI\FilmOS\Prompting\Adapter\ProviderId;

/**
 * Resolves the RenderRequestBuilder for a provider. Symmetric to
 * PromptRendererRegistry: consumers ask by ProviderId and never construct
 * builders — adding Veo/Runway is a registration, not a code change.
 */
interface RenderRequestBuilderRegistry
{
    public function get(ProviderId $provider): RenderRequestBuilder;

    public function has(ProviderId $provider): bool;
}
