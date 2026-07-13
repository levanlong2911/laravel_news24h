<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Scene;

/**
 * A named participant in a shot composition.
 *
 * worldObjectRef: optional link to a WorldObject by objectId.
 * Nodes without a worldObjectRef are scene-only (e.g., the camera itself).
 */
final class SceneNode
{
    public function __construct(
        public readonly string      $id,
        public readonly SceneNodeType $type,
        public readonly string      $label,
        public readonly ?string     $worldObjectRef = null,
    ) {}
}
