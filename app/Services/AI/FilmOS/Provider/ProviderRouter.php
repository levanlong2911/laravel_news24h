<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Provider;

use App\Services\AI\FilmOS\Capability\CapabilityRegistry;
use App\Services\AI\FilmOS\Capability\CapabilityType;

/**
 * Selects the best provider for a capability request.
 *
 * Routing priority comes from CapabilityRegistry (via CapabilityDescriptor::priority).
 * ProviderRegistry is consulted for latency/cost metadata to enrich the returned route.
 *
 * If no CapabilityDescriptor is registered for the requested capability,
 * ProviderRouter throws ProviderException — the caller must register descriptors first.
 *
 * Routing is deterministic: same CapabilityRegistry state + same capability → same route.
 * This is required for providerRouteHash stability across replay runs.
 */
final class ProviderRouter
{
    public function __construct(
        private readonly CapabilityRegistry $capabilities,
        private readonly ProviderRegistry   $providers,
    ) {}

    /**
     * Route a task to the highest-priority provider for a capability.
     *
     * @throws ProviderException  if no provider is registered for the capability
     */
    public function route(string $taskId, CapabilityType $capability): ProviderRoute
    {
        $descriptor = $this->capabilities->best($capability);
        if ($descriptor === null) {
            throw new ProviderException(
                "No provider registered for capability '{$capability->value}' (taskId={$taskId})"
            );
        }

        $provider = $this->providers->get($descriptor->providerName);

        return new ProviderRoute(
            taskId:             $taskId,
            capability:         $capability,
            providerId:         $descriptor->providerName,
            estimatedCostUsd:   $descriptor->costPerCallUsd,
            estimatedLatencyMs: $provider?->latencyP50Ms ?? 0.0,
        );
    }

    /**
     * Route a batch of tasks, each specifying which capability it needs.
     *
     * @param  array<string, CapabilityType>  $tasks  taskId → CapabilityType
     * @return ProviderRoute[]  indexed by taskId
     */
    public function routeAll(array $tasks): array
    {
        $routes = [];
        foreach ($tasks as $taskId => $capability) {
            $routes[$taskId] = $this->route($taskId, $capability);
        }
        return $routes;
    }
}
