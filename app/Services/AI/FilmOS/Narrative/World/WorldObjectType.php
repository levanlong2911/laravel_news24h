<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\World;

/**
 * What a world object IS (its identity), answering "what exists?".
 * Orthogonal to SceneNodeType, which answers "how does it participate
 * visually?" (subject/background/camera/light). Never conflate the two:
 * a sun is a world `environment` object that participates as a scene `light`.
 *
 * VEHICLE and ANIMAL were added 2026-07-13 for real benchmark scenarios
 * (supercar_chase, yacht_drone_dive, wild_stallion) — additive, nothing
 * switches on this enum. Forcing a hero car into `prop` or a stallion into
 * `character` would be a semantic downgrade of D3 World knowledge.
 */
enum WorldObjectType: string
{
    case CHARACTER   = 'character';
    case PROP        = 'prop';
    case LOCATION    = 'location';
    case ENVIRONMENT = 'environment';
    case VEHICLE     = 'vehicle';
    case ANIMAL      = 'animal';
}
