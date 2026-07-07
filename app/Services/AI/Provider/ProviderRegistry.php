<?php

namespace App\Services\AI\Provider;

use App\Services\AI\Provider\Kling\KlingVideoProvider;

/**
 * Registry of available video render providers.
 *
 * Boot once (in AppServiceProvider or a dedicated provider), then inject into jobs
 * via Laravel's service container. Jobs use providerId strings; no concrete import needed.
 *
 * Adding a provider:
 *   $registry->register('veo', fn() => VeoVideoProvider::fromConfig());
 */
final class ProviderRegistry
{
    /** @var array<string, \Closure(): RenderVideoProvider> */
    private array $factories = [];

    public function register(string $name, \Closure $factory): void
    {
        $this->factories[$name] = $factory;
    }

    public function make(string $name): RenderVideoProvider
    {
        if (! isset($this->factories[$name])) {
            $available = implode(', ', array_keys($this->factories));
            throw new \InvalidArgumentException(
                "Provider '{$name}' not registered. Available: [{$available}]"
            );
        }

        return ($this->factories[$name])();
    }

    public function has(string $name): bool
    {
        return isset($this->factories[$name]);
    }

    /** @return string[] */
    public function registered(): array
    {
        return array_keys($this->factories);
    }

    /**
     * Returns the configured default provider.
     * Reads config('ai.default_render_provider', 'kling').
     */
    public function defaultProvider(): RenderVideoProvider
    {
        $name = (string) config('ai.default_render_provider', 'kling');
        return $this->make($name);
    }

    /**
     * Fallback registry for contexts without the Laravel container (tests, CLI one-offs).
     * In production, ProviderRegistry is resolved via AiServiceProvider.
     */
    public static function withDefaults(): self
    {
        $registry = new self();
        $registry->register('kling', fn () => KlingVideoProvider::fromConfig());
        return $registry;
    }
}
