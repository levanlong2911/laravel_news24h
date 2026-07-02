<?php

namespace App\Services\AI\SceneGraph;

/**
 * Approximate cost-per-generation by provider (USD).
 * Used by GraphAssembler to populate estimated_cost in SceneGraph.
 *
 * These are estimates for dashboard/reporting — not billing.
 * Prices should be revised when actual provider invoices are available.
 */
final class ProviderPricing
{
    // Cost per shot (one image or one video clip)
    private const COST_PER_SHOT = [
        'flux'     => 0.010,  // ~$0.01 per 1024×1024 Flux image
        'kling'    => 0.030,  // ~$0.03 per Kling video clip (2–5s)
        'kenburns' => 0.010,  // Same as Flux (Ken Burns animates a static image)
    ];

    private const VOICE_PER_SECOND = 0.000;  // TTS not yet implemented; set when added

    public static function estimateShot(string $provider): float
    {
        return self::COST_PER_SHOT[strtolower($provider)] ?? 0.010;
    }

    /**
     * Build the estimated_cost block for SceneGraph top level.
     *
     * @param  array<string,float> $costsByProvider  Accumulated costs keyed by provider
     * @param  float               $totalDurationSec  For voice cost projection
     */
    public static function buildSummary(array $costsByProvider, float $totalDurationSec = 0.0): array
    {
        $flux     = round($costsByProvider['flux']     ?? 0.0, 4);
        $kling    = round($costsByProvider['kling']    ?? 0.0, 4);
        $kenburns = round($costsByProvider['kenburns'] ?? 0.0, 4);
        $voice    = round(self::VOICE_PER_SECOND * $totalDurationSec, 4);

        return [
            'flux'     => $flux,
            'kling'    => $kling,
            'kenburns' => $kenburns,
            'voice'    => $voice,
            'total'    => round($flux + $kling + $kenburns + $voice, 4),
        ];
    }
}
