<?php

declare(strict_types=1);

namespace App\Video\Concept\Canonical\Enums;

enum ConstraintPrimitive: string
{
    case COUNT = 'count';
    case PROPORTION = 'proportion';
    case POSITION = 'position';
    case ORDER = 'order';
    case CONNECTIVITY = 'connectivity';
    case CONTINUITY = 'continuity';
    case VISIBILITY = 'visibility';
    case MATERIAL = 'material';
    case STATE = 'state';
    case EXCLUSION = 'exclusion';
    case GEOMETRY = 'geometry';
    case LAYOUT = 'layout';
    case GROUPING = 'grouping';
    case ALIGNMENT = 'alignment';
    case CONTAINMENT = 'containment';
    case SYMMETRY = 'symmetry';
}
