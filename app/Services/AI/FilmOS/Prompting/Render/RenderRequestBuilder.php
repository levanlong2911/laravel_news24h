<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompting\Render;

use App\Services\AI\FilmOS\Prompting\Adapter\ProviderId;
use App\Services\AI\FilmOS\Prompting\Adapter\RenderedPrompt;

/**
 * Builds a vendor-neutral RenderRequest from a RenderedPrompt + RenderOptions.
 *
 * Mirror of PromptRenderer: one implementation per provider, resolved through a
 * registry. The interface stops at RenderRequest on purpose — mapping a
 * RenderRequest into a provider's submit() payload is provider-specific (Kling
 * needs mode/cfg_scale; Veo will not), so that step lives on the concrete
 * builder, NOT on this contract.
 */
interface RenderRequestBuilder
{
    public function provider(): ProviderId;

    public function build(RenderedPrompt $prompt, RenderOptions $options): RenderRequest;
}
