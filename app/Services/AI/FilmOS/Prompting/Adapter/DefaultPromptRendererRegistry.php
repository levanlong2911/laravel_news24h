<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompting\Adapter;

final class DefaultPromptRendererRegistry implements PromptRendererRegistry
{
    /** @var array<string, PromptRenderer> keyed by ProviderId value */
    private array $renderers = [];

    /** @param PromptRenderer[] $renderers */
    public function __construct(array $renderers = [])
    {
        foreach ($renderers as $renderer) {
            $this->register($renderer);
        }
    }

    public function register(PromptRenderer $renderer): void
    {
        $this->renderers[$renderer->provider()->value] = $renderer;
    }

    public function get(ProviderId $provider): PromptRenderer
    {
        return $this->renderers[$provider->value]
            ?? throw UnknownProviderException::for($provider);
    }

    public function has(ProviderId $provider): bool
    {
        return isset($this->renderers[$provider->value]);
    }
}
