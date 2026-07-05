<?php

namespace App\Services\AI\AFOS\Backend;

/**
 * BackendCapabilityRegistry — global capability map for all Render Backends.
 *
 * PassManager queries this before CameraIR → prompt serialization.
 * If a backend lacks a capability, PassManager degrades gracefully.
 *
 * Register backends in AfosServiceProvider (or bootstrap equivalent):
 *   BackendCapabilityRegistry::register(KlingCapability::make());
 *   BackendCapabilityRegistry::register(VeoCapability::make());
 */
final class BackendCapabilityRegistry
{
    /** @var BackendCapability[] keyed by backendId */
    private static array $map = [];

    public static function register(BackendCapability $cap): void
    {
        self::$map[$cap->backendId] = $cap;
    }

    public static function get(string $backendId): ?BackendCapability
    {
        return self::$map[$backendId] ?? null;
    }

    /** @return BackendCapability[] */
    public static function all(): array
    {
        return array_values(self::$map);
    }

    /** True if any registered backend supports the given capability flag. */
    public static function anySupports(string $capabilityProperty): bool
    {
        foreach (self::$map as $cap) {
            if (property_exists($cap, $capabilityProperty) && $cap->{$capabilityProperty}) {
                return true;
            }
        }
        return false;
    }
}
