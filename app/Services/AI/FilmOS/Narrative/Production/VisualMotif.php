<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Production;

/**
 * A visual element that must RECUR across the piece ("spiral", "cold breath",
 * "silhouette") — recurrence is what buys continuity from video models.
 * Importance tells adapters which motifs to repeat harder.
 *
 * Immutable.
 */
final class VisualMotif
{
    public function __construct(
        public readonly string          $label,
        public readonly MotifImportance $importance = MotifImportance::SECONDARY,
    ) {}
}
