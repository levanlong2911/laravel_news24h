<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompting\Render;

use App\Services\AI\FilmOS\Prompting\Adapter\ProviderId;

final class DefaultRenderRequestBuilderRegistry implements RenderRequestBuilderRegistry
{
    /** @var array<string, RenderRequestBuilder> keyed by ProviderId value */
    private array $builders = [];

    /** @param RenderRequestBuilder[] $builders */
    public function __construct(array $builders = [])
    {
        foreach ($builders as $builder) {
            $this->register($builder);
        }
    }

    public function register(RenderRequestBuilder $builder): void
    {
        $this->builders[$builder->provider()->value] = $builder;
    }

    public function get(ProviderId $provider): RenderRequestBuilder
    {
        return $this->builders[$provider->value]
            ?? throw UnknownRenderBuilderException::for($provider);
    }

    public function has(ProviderId $provider): bool
    {
        return isset($this->builders[$provider->value]);
    }
}
