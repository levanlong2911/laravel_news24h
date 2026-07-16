<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Timeline;

use App\Services\AI\FilmOS\Narrative\Character\CharacterEmotion;
use App\Services\AI\FilmOS\Narrative\Character\CharacterMemory;
use App\Services\AI\FilmOS\Narrative\Character\CharacterProfile;
use App\Services\AI\FilmOS\Narrative\Performance\PerformanceDesign;
use App\Services\AI\FilmOS\Narrative\Production\ProductionPlan;
use App\Services\AI\FilmOS\Narrative\Story\StoryShot;
use App\Services\AI\FilmOS\Narrative\Scene\CameraConfiguration;
use App\Services\AI\FilmOS\Narrative\Scene\SceneNode;
use App\Services\AI\FilmOS\Narrative\Scene\SceneRelation;
use App\Services\AI\FilmOS\Narrative\Timeline\Projection\CharacterProjection;
use App\Services\AI\FilmOS\Narrative\Timeline\Projection\PerformanceProjection;
use App\Services\AI\FilmOS\Narrative\Timeline\Projection\ProductionProjection;
use App\Services\AI\FilmOS\Narrative\Timeline\Projection\SceneProjection;
use App\Services\AI\FilmOS\Narrative\Timeline\Projection\StoryProjection;
use App\Services\AI\FilmOS\Narrative\Timeline\Projection\WorldProjection;
use App\Services\AI\FilmOS\Narrative\World\WorldFact;
use App\Services\AI\FilmOS\Narrative\World\WorldObject;

final class NarrativeStateBuilder
{
    // Story domain (D0/D1) — filled by ShotPlannedProjectionHandler
    /** @var array<int, StoryShot> keyed by shot ordinal (last-write-wins per ordinal) */
    private array $shots = [];

    // World domain (D3) — filled by WorldObjectPlaced/Removed/FactAsserted handlers
    /** @var array<string, WorldObject> keyed by objectId */
    private array $worldObjects = [];
    /** @var array<string, WorldFact> keyed by factKey */
    private array $worldFacts   = [];

    // Scene domain (D4) — filled by SceneNodePlaced/Removed/RelationEstablished/CameraConfigured handlers
    /** @var array<string, SceneNode> keyed by nodeId */
    private array $sceneNodes     = [];
    /** @var array<string, SceneRelation> keyed by "{fromId}:{toId}:{type}" */
    private array $sceneRelations = [];
    /** @var array<int, CameraConfiguration> keyed by shot ordinal */
    private array $sceneCameras   = [];
    /** @var array<int, array<string, SceneNode>> shot ordinal => nodes that shot placed */
    private array $sceneNodesByOrdinal = [];

    // Production domain — filled by ProductionPlannedHandler (last-write-wins)
    private ?ProductionPlan $productionPlan = null;

    // Performance domain — filled by PerformanceDirectedHandler (last-write-wins)
    private ?PerformanceDesign $performanceDesign = null;

    // Character domain (D2) — filled by CharacterIntroduced/EmotionChanged handlers
    /** @var array<string, CharacterProfile> keyed by characterId */
    private array $characterProfiles     = [];
    /** @var array<string, int> keyed by characterId */
    private array $characterIntroducedAt = [];
    /** @var array<string, array<int, CharacterEmotion>> [characterId => [ordinal => emotion]] */
    private array $characterEmotions     = [];

    /** Builder receives the VO — same pattern as upsertWorldObject/introduceCharacter. */
    public function addShot(StoryShot $shot): void
    {
        $this->shots[$shot->ordinal] = $shot;  // last-write-wins per ordinal
    }

    // World domain (D3) API

    public function upsertWorldObject(WorldObject $object): void
    {
        $this->worldObjects[$object->id] = $object;
    }

    public function removeWorldObject(string $objectId): void
    {
        unset($this->worldObjects[$objectId]);
    }

    public function assertWorldFact(WorldFact $fact): void
    {
        $this->worldFacts[$fact->key] = $fact;  // last-write-wins per key
    }

    // Scene domain (D4) API

    /**
     * $ordinal records WHICH SHOT placed the node, so staging can ask what was
     * in frame at shot N (SceneView::nodesAt). The flat $sceneNodes stays the
     * latest-state view — both semantics coexist, exactly as with cameras.
     */
    public function upsertSceneNode(SceneNode $node, ?int $ordinal = null): void
    {
        $this->sceneNodes[$node->id] = $node;

        if ($ordinal !== null) {
            $this->sceneNodesByOrdinal[$ordinal][$node->id] = $node;
        }
    }

    public function removeSceneNode(string $nodeId): void
    {
        unset($this->sceneNodes[$nodeId]);
    }

    public function establishSceneRelation(SceneRelation $relation): void
    {
        $key = "{$relation->fromId}:{$relation->toId}:{$relation->type->value}";
        $this->sceneRelations[$key] = $relation;
    }

    public function configureCamera(int $ordinal, CameraConfiguration $camera): void
    {
        $this->sceneCameras[$ordinal] = $camera;
    }

    // Character domain (D2) API

    /**
     * Last-write-wins per characterId. A duplicate introduction overwrites the
     * profile silently at projection level; detecting duplicates is a D5 QA concern.
     */
    public function introduceCharacter(CharacterProfile $profile, int $ordinal): void
    {
        $this->characterProfiles[$profile->id]     = $profile;
        $this->characterIntroducedAt[$profile->id] = $ordinal;
    }

    /**
     * Records one entry in the character's emotion timeline (last-write-wins per
     * ordinal). Emotions for characters never introduced are dropped at build()
     * time — projection stays tolerant; the orphan event is a D5 QA concern.
     */
    public function recordEmotion(string $characterId, int $ordinal, CharacterEmotion $emotion): void
    {
        $this->characterEmotions[$characterId][$ordinal] = $emotion;
    }

    // Production domain API — last-write-wins; duplicate plan = future QA anomaly

    public function setProductionPlan(ProductionPlan $plan): void
    {
        $this->productionPlan = $plan;
    }

    // Performance domain API — last-write-wins; duplicate design = future QA anomaly

    public function setPerformanceDesign(PerformanceDesign $design): void
    {
        $this->performanceDesign = $design;
    }

    public function build(int $schemaVersion, ProjectionMetadata $metadata): NarrativeState
    {
        $memories = [];
        foreach ($this->characterProfiles as $id => $profile) {
            $memories[$id] = new CharacterMemory(
                profile:         $profile,
                introducedAt:    $this->characterIntroducedAt[$id],
                emotionTimeline: $this->characterEmotions[$id] ?? [],
            );
        }

        return new NarrativeState(
            schemaVersion: $schemaVersion,
            metadata:      $metadata,
            story:         new StoryProjection($this->shots),
            characters:    new CharacterProjection($memories),
            world:         new WorldProjection($this->worldObjects, $this->worldFacts),
            scene:         new SceneProjection($this->sceneNodes, $this->sceneRelations, $this->sceneCameras, $this->sceneNodesByOrdinal),
            production:    new ProductionProjection($this->productionPlan),
            performance:   new PerformanceProjection($this->performanceDesign),
        );
    }
}
