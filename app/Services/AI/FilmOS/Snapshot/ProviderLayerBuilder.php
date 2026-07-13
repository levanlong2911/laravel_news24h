<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Snapshot;

use App\Services\AI\FilmOS\Provider\ProviderRoute;
use App\Services\AI\FilmOS\Provider\ProviderRuntime;

/**
 * Builds Phase C snapshot hashes.
 *
 * Data source: ProviderRuntime::all() — the routes taken during this run.
 * This builder has NO knowledge of CapabilityRegistry or ProviderRegistry;
 * it reads only the factual record of what happened.
 *
 * Hash contracts (ADR-016 Phase C):
 *
 *   capabilityHash
 *     Input: (taskId, capability.value) per route, sorted by taskId.
 *     Excludes: providerId — stable when Kling swapped for Veo.
 *     Changes: when the plan requires a different capability type.
 *
 *   providerRouteHash
 *     Input: (taskId, providerId) per route, sorted by taskId.
 *     Excludes: capability — captures only the routing decision.
 *     Changes: when Kling → Veo (same capability, different provider).
 */
final class ProviderLayerBuilder
{
    public function __construct(
        private readonly HashSerializer $serializer = new JsonHashSerializer(),
    ) {}

    /**
     * @param  ProviderRuntime|array<string, ProviderRoute>  $source  routes from this run
     */
    public function build(ProviderRuntime|array $source): ProviderSection
    {
        $routes = $source instanceof ProviderRuntime ? $source->all() : $source;
        ksort($routes);

        return new ProviderSection(
            capabilityHash:    $this->buildCapabilityHash($routes),
            providerRouteHash: $this->buildProviderRouteHash($routes),
        );
    }

    // ── Private builders ──────────────────────────────────────────────────────

    /**
     * Hash of capabilities required: (taskId, capability.value).
     * providerId deliberately excluded — stable across provider switches.
     *
     * @param  array<string, ProviderRoute>  $routes  sorted by taskId
     */
    private function buildCapabilityHash(array $routes): string
    {
        $canonical = array_map(
            static fn(ProviderRoute $r) => $r->capabilityData(),
            array_values($routes),
        );

        return $this->serializer->sha256($canonical);
    }

    /**
     * Hash of routing decisions: (taskId, providerId).
     * Changes when provider is switched; capability excluded from this hash.
     *
     * @param  array<string, ProviderRoute>  $routes  sorted by taskId
     */
    private function buildProviderRouteHash(array $routes): string
    {
        $canonical = array_map(
            static fn(ProviderRoute $r) => $r->routeData(),
            array_values($routes),
        );

        return $this->serializer->sha256($canonical);
    }
}
