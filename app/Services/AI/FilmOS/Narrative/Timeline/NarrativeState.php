<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Timeline;

use App\Services\AI\FilmOS\Narrative\Character\CharacterView;
use App\Services\AI\FilmOS\Narrative\Production\ProductionView;
use App\Services\AI\FilmOS\Narrative\Scene\SceneView;
use App\Services\AI\FilmOS\Narrative\Story\StoryView;
use App\Services\AI\FilmOS\Narrative\World\WorldView;

final class NarrativeState
{
    public const SCHEMA_VERSION = 1;

    public function __construct(
        /** Bump when adding fields that could break existing consumers. */
        public readonly int                $schemaVersion,
        public readonly ProjectionMetadata $metadata,
        public readonly StoryView          $story,      // all five domains behind interfaces
        public readonly CharacterView      $characters,
        public readonly WorldView          $world,
        public readonly SceneView          $scene,
        public readonly ProductionView     $production, // staging knowledge — last Knowledge layer before Prompting
    ) {}
}
