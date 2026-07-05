<?php

namespace App\Services\AI\AFOS\Ontology;

/**
 * EntityType — semantic category of a scene entity.
 *
 * Used by EntityRef to classify the primary subject so that KlingPromptPlanningPass
 * can select vocabulary appropriate to the entity's nature without needing to
 * inspect the raw entity ID string.
 *
 * Expanding this enum never breaks existing passes — they pattern-match on known
 * cases and fall through to GENERIC for unknown ones.
 */
enum EntityType: string
{
    case WATER_FEATURE         = 'water_feature';   // pool, fountain, lake, reflection
    case ARCHITECTURAL_ELEMENT = 'arch_element';    // terrace, facade, wall, balcony
    case INTERIOR_SPACE        = 'interior';        // room, corridor, living area
    case MATERIAL              = 'material';        // stone, marble, wood, glass surface
    case STRUCTURE             = 'structure';       // villa, building, tower
    case LANDSCAPE             = 'landscape';       // view, horizon, garden, mountain
    case PERSON                = 'person';          // athlete, model, subject portrait
    case VEHICLE               = 'vehicle';         // car, boat, aircraft
    case LIGHT_QUALITY         = 'light_quality';   // sunset, dawn, shadow pattern
    case GENERIC               = 'generic';         // fallback — entity not classified
}
