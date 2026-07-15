<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Production;

/** How strongly a visual motif must recur. Extensible (OPTIONAL later) without contract change. */
enum MotifImportance: string
{
    case PRIMARY   = 'primary';
    case SECONDARY = 'secondary';
}
