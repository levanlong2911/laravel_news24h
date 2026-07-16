<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Timeline\Projection;

use App\Services\AI\FilmOS\Narrative\Scene\CameraConfiguration;
use App\Services\AI\FilmOS\Narrative\Scene\SceneNode;
use App\Services\AI\FilmOS\Narrative\Scene\SceneRelation;
use App\Services\AI\FilmOS\Narrative\Scene\SceneView;

/**
 * Snapshot of scene composition state at a given timeline point.
 *
 * Design contract — three distinct semantics, one object:
 *
 *   $nodes      LATEST state: last-write-wins per nodeId.
 *               Analogous to D3 WorldProjection::$objects.
 *               "Which nodes are present in the scene right now?"
 *
 *   $relations  LATEST state: last-write-wins per "{fromId}:{toId}:{type}" key.
 *               "What are the current semantic relationships?"
 *
 *   $cameras    ACCUMULATED HISTORY: one entry per shot ordinal, never overwritten
 *               by later ordinals — each shot keeps its own camera setup forever.
 *               Analogous to D0 StoryProjection::$shots.
 *               "What was the camera configuration at shot N?"
 *               → getCamera(0) and getCamera(3) can both be non-null simultaneously.
 *
 *   $nodesByOrdinal  ACCUMULATED HISTORY: the nodes placed FOR each shot, same
 *               semantics as $cameras. "What was in frame at shot N?"
 *               $nodes stays the latest-state view and is unchanged; this adds
 *               the time dimension staging needs (a subject that only enters at
 *               the payoff must not be in frame during the hook). Removal
 *               affects only $nodes — history is what a shot placed, and the
 *               past cannot be un-placed.
 *
 * Do NOT treat $cameras as "the current camera". It is the full camera history.
 * If you only need the latest shot's camera, use getCamera($lastOrdinal).
 *
 * D2 (CharacterMemory) and D5 (QA) MUST depend on SceneView, not this class.
 */
final class SceneProjection implements SceneView
{
    /**
     * @param array<string, SceneNode>            $nodes          keyed by nodeId (latest state)
     * @param array<string, SceneRelation>        $relations      keyed by "{fromId}:{toId}:{type}" (latest state)
     * @param array<int, CameraConfiguration>     $cameras        keyed by shot ordinal (accumulated history)
     * @param array<int, array<string, SceneNode>> $nodesByOrdinal shot ordinal => nodes placed for it (history)
     */
    public function __construct(
        public readonly array $nodes          = [],
        public readonly array $relations      = [],
        public readonly array $cameras        = [],
        public readonly array $nodesByOrdinal = [],
    ) {}

    public function hasNode(string $nodeId): bool
    {
        return isset($this->nodes[$nodeId]);
    }

    public function getCamera(int $ordinal): ?CameraConfiguration
    {
        return $this->cameras[$ordinal] ?? null;
    }

    /** @return array<string, SceneNode> */
    public function allNodes(): array { return $this->nodes; }

    /** @return array<string, SceneNode> nodes placed for shot $ordinal */
    public function nodesAt(int $ordinal): array { return $this->nodesByOrdinal[$ordinal] ?? []; }

    /** @return array<string, SceneRelation> */
    public function allRelations(): array { return $this->relations; }

    /** @return array<int, CameraConfiguration> */
    public function allCameras(): array { return $this->cameras; }
}
