<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompting\Adapter;

/**
 * The closed set of video providers FilmOS renders prompts for.
 * A closed enum (not a free string) because the provider list is known and
 * finite; switch to a string only if dynamic third-party plugins ever appear.
 */
enum ProviderId: string
{
    case KLING  = 'kling';
    case VEO    = 'veo';
    case RUNWAY = 'runway';
}
