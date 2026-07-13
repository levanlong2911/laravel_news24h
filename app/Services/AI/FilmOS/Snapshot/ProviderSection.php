<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Snapshot;

/**
 * Phase C snapshot section — Provider + Capability layer.
 *
 * Hash contracts (ADR-016 Phase C):
 *
 *   capabilityHash    — hash of capabilities required by the plan, sorted by taskId.
 *                       Stable when provider changes (Kling → Veo → same hash).
 *                       Changes only when the plan requires a different capability type.
 *
 *   providerRouteHash — hash of taskId → providerId mapping.
 *                       Changes when provider changes (Kling → Veo → different hash).
 *                       Does NOT change when the capability type stays the same.
 *
 * Separation intent: capabilityHash tests "what was asked for",
 * providerRouteHash tests "who did it". They can diverge independently.
 */
final class ProviderSection implements SnapshotSection
{
    public function __construct(
        public readonly string $capabilityHash,
        public readonly string $providerRouteHash,
    ) {}

    public static function name(): string { return 'provider'; }

    public static function requiredFields(): array
    {
        return ['capabilityHash', 'providerRouteHash'];
    }

    public static function optionalFields(): array { return []; }

    public function fields(): array
    {
        return [
            'capabilityHash'    => $this->capabilityHash,
            'providerRouteHash' => $this->providerRouteHash,
        ];
    }
}
