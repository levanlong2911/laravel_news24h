<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompting\IR;

/**
 * The LOOK the piece is shot in — authored per scenario, because it is a
 * property of the subject matter, not of the renderer.
 *
 * Without this the renderer must hardcode one style for every topic, and an
 * NFL play, a wildlife documentary and a car commercial all come out as the
 * same "cinematic, film grain, shallow depth of field" footage. A closed set
 * (not a free string) so each vendor adapter maps style → its own wording,
 * the same way it maps TELEPHOTO → "85mm".
 */
enum VisualStyle: string
{
    case CINEMATIC          = 'cinematic';            // general narrative film
    case SPORTS_DOCUMENTARY = 'sports_documentary';   // broadcast/NFL Films look
    case NATURE_DOCUMENTARY = 'nature_documentary';   // BBC Earth, long lens, natural colour
    case LUXURY_COMMERCIAL  = 'luxury_commercial';    // glossy product/automotive
    case VINTAGE_FILM       = 'vintage_film';         // 35mm, grain, halation
    case DIGITAL_CLEAN      = 'digital_clean';        // modern, clinical, no grain
    case HORROR             = 'horror';               // cold, high-shadow, desaturated
    case ANIME              = 'anime';
    case COMIC              = 'comic';
}
