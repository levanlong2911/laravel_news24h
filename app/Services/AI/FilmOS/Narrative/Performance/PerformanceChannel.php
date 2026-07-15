<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Performance;

/**
 * The body channel a performance cue plays on.
 *
 * Typed so knowledge stays queryable without NLP: QA can detect two
 * conflicting GAZE cues, planners learn cue distribution per channel,
 * adapters group cues, benchmark aggregates by channel.
 */
enum PerformanceChannel: string
{
    case GAZE    = 'gaze';     // eyes flick left, locks downfield
    case FACE    = 'face';     // jaw tightens, tiny smile
    case BREATH  = 'breath';   // half breath, holds breath
    case POSTURE = 'posture';  // shoulders drop, weight shifts
    case HANDS   = 'hands';    // grip tightens, fingers tremble
    case VOICE   = 'voice';    // voice cracks, whisper
}
