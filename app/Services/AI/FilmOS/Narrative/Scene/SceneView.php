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

    /**
     * The nodes placed FOR a given shot — the scene's time dimension, symmetric
     * with getCamera($ordinal). allNodes() answers "which nodes exist at all";
     * this answers "what is in frame in shot N", which is what staging needs
     * (a receiver that only appears at the payoff must not exist in the hook).
     *
     * @return array<string, SceneNode> keyed by nodeId; empty when the shot placed none
     */
    public function nodesAt(int $ordinal): array;

    /** @return array<string, SceneRelation> keyed by "{fromId}:{toId}:{type}" */
    public function allRelations(): array;

    /** @return array<int, CameraConfiguration> keyed by shot ordinal */
    public function allCameras(): array;
}
