<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Capability;

/**
 * Central registry: capability type → ranked list of provider descriptors.
 *
 * The registry owns the mapping "what can be done" → "who can do it".
 * Nothing else in FilmOS should reference provider names directly.
 *
 * Usage:
 *   $registry->register(new CapabilityDescriptor('kling',  CapabilityType::IMAGE_TO_VIDEO, priority: 100));
 *   $registry->register(new CapabilityDescriptor('runway', CapabilityType::IMAGE_TO_VIDEO, priority: 80));
 *   $registry->resolve(CapabilityType::IMAGE_TO_VIDEO); // [kling, runway]
 */
final class CapabilityRegistry
{
    /** @var array<string, CapabilityDescriptor[]> capabilityValue → descriptors sorted by priority */
    private array $registry = [];

    // ── Registration ──────────────────────────────────────────────────────────

    public function register(CapabilityDescriptor $descriptor): void
    {
        $key = $descriptor->capability->value;
        $this->registry[$key][] = $descriptor;
        usort(
            $this->registry[$key],
            static fn(CapabilityDescriptor $a, CapabilityDescriptor $b) => $b->priority <=> $a->priority,
        );
    }

    // ── Resolution ───────────────────────────────────────────────────────────

    /**
     * All providers that support the capability, ordered by priority (highest first).
     * @return CapabilityDescriptor[]
     */
    public function resolve(CapabilityType $capability): array
    {
        return $this->registry[$capability->value] ?? [];
    }

    /**
     * The highest-priority provider for a capability.
     * Returns null if no provider registered.
     */
    public function best(CapabilityType $capability): ?CapabilityDescriptor
    {
        return $this->resolve($capability)[0] ?? null;
    }

    /** True if at least one provider is registered for this capability. */
    public function supports(CapabilityType $capability): bool
    {
        return !empty($this->registry[$capability->value]);
    }

    // ── Introspection ─────────────────────────────────────────────────────────

    /**
     * All capabilities that a named provider supports.
     * @return CapabilityType[]
     */
    public function capabilitiesOf(string $providerName): array
    {
        $result = [];
        foreach ($this->registry as $descriptors) {
            foreach ($descriptors as $d) {
                if ($d->providerName === $providerName) {
                    $result[] = $d->capability;
                }
            }
        }
        return $result;
    }

    /** All unique provider names registered. */
    public function providers(): array
    {
        $names = [];
        foreach ($this->registry as $descriptors) {
            foreach ($descriptors as $d) {
                $names[$d->providerName] = true;
            }
        }
        return array_keys($names);
    }

    /** All capabilities that have at least one provider registered. */
    public function registeredCapabilities(): array
    {
        return array_map(
            static fn(string $v) => CapabilityType::from($v),
            array_keys($this->registry),
        );
    }

    /** @return array<string, mixed> registry dump for observability */
    public function snapshot(): array
    {
        $out = [];
        foreach ($this->registry as $capValue => $descriptors) {
            $out[$capValue] = array_map(fn(CapabilityDescriptor $d) => $d->toArray(), $descriptors);
        }
        return $out;
    }
}
