<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompting\Render;

use App\Services\AI\FilmOS\Prompting\Adapter\ProviderId;

final class UnknownRenderBuilderException extends \RuntimeException
{
    public static function for(ProviderId $provider): self
    {
        return new self("No RenderRequestBuilder registered for provider '{$provider->value}'.");
    }
}
