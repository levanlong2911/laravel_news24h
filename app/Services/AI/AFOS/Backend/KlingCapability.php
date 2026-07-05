<?php

namespace App\Services\AI\AFOS\Backend;

/**
 * KlingCapability — capability descriptor for Kling v1.6 text-to-video.
 *
 * Kling supports natural-language lens and motion descriptions embedded in the
 * prompt, but does NOT support structured JSON, scene graphs, or explicit
 * keyframe sequences. The Backend serializes these as English prose.
 *
 * Cost: ~$0.14 per 5-second clip (Kling Pro tier, 720p, Jul 2026 pricing).
 * Latency: ~45–90 seconds depending on queue.
 */
final class KlingCapability
{
    public static function make(): BackendCapability
    {
        return new BackendCapability(
            backendId:             'kling',
            supportsLens:          true,   // lens described as prose: "85mm telephoto"
            supportsNegativeSpace: true,   // described as prose: "right side open to sky"
            supportsMotionCurve:   true,   // described as prose: "slow push accelerating"
            supportsDepthLayers:   true,   // described as prose: "foreground/background"
            supportsKeyframes:     false,  // no multi-frame control
            supportsSceneGraph:    false,  // no JSON input
            supportsJsonPrompt:    false,  // text-only
            maxDurationSec:        10,     // Kling Pro max 10s
            costPerSecondUsd:      0.028,  // $0.14 / 5s
            avgLatencySec:         60.0,
        );
    }
}
