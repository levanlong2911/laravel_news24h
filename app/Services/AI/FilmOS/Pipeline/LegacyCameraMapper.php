<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Pipeline;

/**
 * Maps legacy ExecutionContext.styleRule to the camera semantic expected by KlingSerializer.
 *
 * KlingSerializer accepts: 'close_up', 'medium_shot', 'wide', 'overhead', 'tracking'.
 * CameraStrategy produces styleRule['lens'] values: 35 (ESCALATE), 50 (ESTABLISH), 85 (REVEAL).
 *
 * Lens thresholds are a first-pass approximation.
 * C.8 will replace this with native planning that produces camera semantics directly.
 */
final class LegacyCameraMapper
{
    public function map(array $styleRule): string
    {
        $lens = (int) ($styleRule['lens'] ?? 50);

        return match (true) {
            $lens >= 70 => 'close_up',    // 70mm+ telephoto → tight framing
            $lens >= 40 => 'medium_shot', // 40–69mm normal → medium framing
            default     => 'wide',        // ≤39mm wide-angle → establishing
        };
    }
}
