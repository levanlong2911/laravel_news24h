<?php

namespace App\Services\AI\AFOS\Backend;

/**
 * BackendCapability — describes what a Render Backend can execute.
 *
 * The Planner uses this to degrade gracefully when a Backend lacks a feature.
 * Example: if Backend does not support lens specification, Camera Pass should
 * omit lens parameters or use a default, not fail.
 *
 * Experience Engine uses costPerSecond and avgLatencySec in the Cost Model
 * to compute utility = quality - λ_latency × latency - λ_cost × cost.
 */
final class BackendCapability
{
    public function __construct(
        public readonly string $backendId,

        // Prompt capabilities
        public readonly bool $supportsLens,           // focal length + aperture specification
        public readonly bool $supportsNegativeSpace,  // explicit negative space direction
        public readonly bool $supportsMotionCurve,    // velocity curve / easing control
        public readonly bool $supportsDepthLayers,    // foreground / midground / background

        // Advanced capabilities (Phase B+)
        public readonly bool $supportsKeyframes,      // multi-frame camera keyframe sequence
        public readonly bool $supportsSceneGraph,     // structured JSON scene graph
        public readonly bool $supportsJsonPrompt,     // machine-readable JSON input

        // Production constraints
        public readonly int   $maxDurationSec,        // max supported clip length
        public readonly float $costPerSecondUsd,      // estimated cost per second of output
        public readonly float $avgLatencySec,         // typical generation time in seconds
    ) {}
}
