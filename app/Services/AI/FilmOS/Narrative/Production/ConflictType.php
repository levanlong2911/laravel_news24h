<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Production;

/**
 * The KIND of force working against the objective — typed so a future
 * Director Planner can learn per-kind staging responses
 * (e.g. TIME conflict → tighter framing), which a bare string list
 * could never teach.
 */
enum ConflictType: string
{
    case PHYSICAL      = 'physical';       // pocket collapsing, injury, obstacle
    case ENVIRONMENTAL = 'environmental';  // rain, darkness, slippery field
    case PSYCHOLOGICAL = 'psychological';  // fear, doubt, panic
    case SOCIAL        = 'social';         // crowd pressure, rivalry, expectations
    case TIME          = 'time';           // clock running out, deadline
}
