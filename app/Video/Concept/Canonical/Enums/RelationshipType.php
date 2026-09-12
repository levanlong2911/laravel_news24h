<?php

declare(strict_types=1);

namespace App\Video\Concept\Canonical\Enums;

enum RelationshipType: string
{
    case COUNT = 'count';
    case GROUPING = 'grouping';
    case ONE_TO_ONE = 'one_to_one';
    case PROPORTION = 'proportion';
    case POSITION = 'position';
    case ORDER = 'order';
    case CONTINUITY = 'continuity';
    case CONNECTIVITY = 'connectivity';
    case SYMMETRY = 'symmetry';
    case CONTAINMENT = 'containment';
    case ALIGNMENT = 'alignment';
}
