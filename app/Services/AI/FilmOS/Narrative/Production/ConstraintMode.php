<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Production;

/**
 * Polarity of a visual constraint — covers both "never show X" and
 * "always keep Y visible" (hence not "NegativeIntent").
 */
enum ConstraintMode: string
{
    case NEVER  = 'never';
    case ALWAYS = 'always';
}
