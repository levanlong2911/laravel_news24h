<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Intent;

enum NarrativeBeat: string
{
    case CONTEXT   = 'context';
    case EVIDENCE  = 'evidence';
    case RESPONSE  = 'response';
    case ADVISORY  = 'advisory';
    case ESTABLISH = 'establish';
    case CLIMAX    = 'climax';
    case RESOLVE   = 'resolve';
}
