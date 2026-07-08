<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Meaning;

enum CausalRelation: string
{
    case CAUSES      = 'causes';
    case ESCALATES   = 'escalates';
    case INDICATES   = 'indicates';
    case CONTRADICTS = 'contradicts';
    case ENABLES     = 'enables';
    case MODULATES   = 'modulates';
}
