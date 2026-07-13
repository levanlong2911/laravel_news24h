<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Scene;

/**
 * Read-only view of the scene state at a projection point.
 *
 * D2 (CharacterMemory) and D5 (QA) depend on this interface, not SceneProjection.
 * cameras are indexed by shot ordinal — query getCamera($ordinal) for per-shot setup.
 */
interface SceneView
{
    public function hasNode(string $nodeId): bool;

    /** Returns the camera configuration recorded for the given shot ordinal, or null. */
    public function getCamera(int $ordinal): ?CameraConfiguration;

    /** @return array<string, SceneNode> keyed by nodeId */
    public function allNodes(): array;

    /** @return array<string, SceneRelation> keyed by "{fromId}:{toId}:{type}" */
    public function allRelations(): array;

    /** @return array<int, CameraConfiguration> keyed by shot ordinal */
    public function allCameras(): array;
}
