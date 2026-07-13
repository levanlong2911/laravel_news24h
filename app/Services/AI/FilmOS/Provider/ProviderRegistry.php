<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Provider;

use App\Services\AI\FilmOS\Capability\CapabilityType;

/**
 * Provider-first registry: provider ID → ProviderDescriptor.
 *
 * Answers "what can Kling do?" and "which providers support TEXT_TO_VIDEO?"
 * Complements CapabilityRegistry which answers capability-first questions.
 *
 * The two registries are independent: CapabilityRegistry drives routing priority
 * (via CapabilityDescriptor::priority), ProviderRegistry drives introspection
 * (benchmarks, observability, capability graph queries).
 */
final class ProviderRegistry
{
    /** @var array<string, ProviderDescriptor>  provider id → descriptor */
    private array $providers = [];

    public function register(ProviderDescriptor $descriptor): void
    {
        $this->providers[$descriptor->id] = $descriptor;
    }

    public function get(string $id): ?ProviderDescriptor
    {
        return $this->providers[$id] ?? null;
    }

    /**
     * All providers that support a given capability.
     * @return ProviderDescriptor[]
     */
    public function forCapability(CapabilityType $type): array
    {
        return array_values(array_filter(
            $this->providers,
            fn(ProviderDescriptor $d) => $d->supports($type),
        ));
    }

    /** @return ProviderDescriptor[] */
    public function all(): array
    {
        return array_values($this->providers);
    }

    /** All unique provider IDs registered. */
    public function ids(): array
    {
        return array_keys($this->providers);
    }

    /** Registry dump for observability and benchmarks. */
    public function snapshot(): array
    {
        $out = [];
        foreach ($this->providers as $id => $descriptor) {
            $out[$id] = $descriptor->toArray();
        }
        return $out;
    }
}
