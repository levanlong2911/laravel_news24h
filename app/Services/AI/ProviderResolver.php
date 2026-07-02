<?php

namespace App\Services\AI;

/**
 * Pure decision function: visual intent → provider name.
 *
 * Rule 1 enforcer: this is the ONLY place in the system that knows provider names.
 * Planners output motion_level/realism/has_human; this class decides the provider.
 *
 * Resolution matrix (priority order — first matching rule wins):
 *
 *   motion=none                           → kenburns  (static image + zoom/pan)
 *   motion=high                           → kling     (fast action needs video gen)
 *   has_human=true  (any non-zero motion) → kling     (humans in motion = video)
 *   realism=photoreal                     → flux      (Flux best-in-class stills)
 *   realism=high + motion in [low,medium] → flux      (quality stills, slow motion)
 *   fallback                              → kenburns  (low motion, no quality req)
 */
final class ProviderResolver
{
    public const PROVIDER_KENBURNS = 'kenburns';
    public const PROVIDER_FLUX     = 'flux';
    public const PROVIDER_KLING    = 'kling';

    public static function resolve(
        string $motionLevel,
        string $realism,
        bool   $hasHuman,
    ): string {
        if ($motionLevel === 'none') {
            return self::PROVIDER_KENBURNS;
        }

        if ($motionLevel === 'high') {
            return self::PROVIDER_KLING;
        }

        if ($hasHuman) {
            // Human subjects with any motion → video model
            return self::PROVIDER_KLING;
        }

        if ($realism === 'photoreal') {
            return self::PROVIDER_FLUX;
        }

        if ($realism === 'high') {
            return self::PROVIDER_FLUX;
        }

        // low/medium motion + low/medium realism + no human → cheap ken burns
        return self::PROVIDER_KENBURNS;
    }

    /** Convenience overload accepting a ShotDTO-shaped array. */
    public static function resolveFromDsl(array $dsl): string
    {
        return self::resolve(
            motionLevel: $dsl['motion_level'] ?? 'none',
            realism:     $dsl['realism']      ?? 'medium',
            hasHuman:    (bool) ($dsl['has_human'] ?? false),
        );
    }
}
