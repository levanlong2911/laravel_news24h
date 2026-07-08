<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Policy;

/**
 * Immutable bag of attributes passed to PolicyEngine.decide().
 *
 * All context is attribute-based — no hardcoded fields.
 * New context dimensions (GPU temperature, customer tier, SLA status…)
 * are added as attributes without changing this class.
 *
 * Convention for attribute keys:
 *   content_type          → 'breaking_news' | 'documentary' | 'entertainment'
 *   customer_tier         → 'premium' | 'standard' | 'budget'
 *   budget_remaining_usd  → float
 *   reviewer_confidence   → float 0.0–1.0
 *   provider_sla.kling    → float 0.0–1.0 (1.0 = fully operational)
 *   gpu_cluster.temp_c    → float
 */
final class PolicyContext
{
    /** @param array<string, mixed> $attributes */
    public function __construct(
        private readonly array $attributes = [],
    ) {}

    public static function from(array $attributes): self
    {
        return new self($attributes);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->attributes);
    }

    public function with(string $key, mixed $value): self
    {
        return new self(array_merge($this->attributes, [$key => $value]));
    }

    public function all(): array
    {
        return $this->attributes;
    }
}
