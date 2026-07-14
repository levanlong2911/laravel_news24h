<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Timeline;

use App\Services\AI\FilmOS\Narrative\Character\CharacterView;
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
        public readonly StoryView          $story,      // all four domains now behind interfaces
        public readonly CharacterView      $characters, // D5/PromptCompiler depend on this interface, not CharacterProjection
        public readonly WorldView          $world,      // D4/D2 depend on this interface, not WorldProjection
        public readonly SceneView          $scene,      // D5/D2 depend on this interface, not SceneProjection
    ) {}
}
