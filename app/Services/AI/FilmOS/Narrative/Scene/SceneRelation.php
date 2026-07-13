<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Scene;

/**
 * A directed semantic relationship between two scene nodes.
 *
 * Keyed in the builder as "{fromId}:{toId}:{type.value}" — last-write-wins per key.
 * Same pair can hold multiple relation types (e.g., camera TARGETS hero AND hero IN_FRAME).
 */
final class SceneRelation
{
    public function __construct(
        public readonly string          $fromId,
        public readonly string          $toId,
        public readonly SceneRelationType $type,
    ) {}
}
