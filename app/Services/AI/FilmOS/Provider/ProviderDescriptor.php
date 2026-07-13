<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Provider;

use App\Services\AI\FilmOS\Capability\CapabilityType;

/**
 * Describes a provider: what it can do and at what cost.
 *
 * This is the provider-first view — inverse of CapabilityDescriptor.
 * CapabilityDescriptor answers "who can do TEXT_TO_VIDEO?"
 * ProviderDescriptor  answers "what can Kling do?"
 *
 * Both views are needed:
 *   CapabilityRegistry + CapabilityDescriptor → capability routing (planner asks)
 *   ProviderRegistry   + ProviderDescriptor   → provider introspection (benchmarks ask)
 */
final class ProviderDescriptor
{
    /**
     * @param  string           $id               Stable provider ID ('kling', 'veo', 'runway')
     * @param  CapabilityType[] $capabilities     All capabilities this provider supports
     * @param  float            $latencyP50Ms     Median latency (ms) — used by ProviderRouter cost model
     * @param  float            $costPerCallUsd   Base cost per API call in USD
     * @param  array            $metadata         Arbitrary provider metadata (region, max resolution, etc.)
     */
    public function __construct(
        public readonly string $id,
        public readonly array  $capabilities,
        public readonly float  $latencyP50Ms   = 15_000.0,
        public readonly float  $costPerCallUsd = 0.0,
        public readonly array  $metadata       = [],
    ) {}

    public function supports(CapabilityType $type): bool
    {
        foreach ($this->capabilities as $cap) {
            if ($cap === $type) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'             => $this->id,
            'capabilities'   => array_map(fn(CapabilityType $c) => $c->value, $this->capabilities),
            'latencyP50Ms'   => $this->latencyP50Ms,
            'costPerCallUsd' => $this->costPerCallUsd,
            'metadata'       => $this->metadata,
        ];
    }
}
