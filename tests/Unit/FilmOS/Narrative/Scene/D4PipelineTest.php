<?php

declare(strict_types=1);

namespace Tests\Unit\FilmOS\Narrative\Scene;

use App\Services\AI\FilmOS\Narrative\Bootstrap\NarrativeBootstrapper;
use App\Services\AI\FilmOS\Narrative\Character\CharacterEventFactory;
use App\Services\AI\FilmOS\Narrative\Production\ProductionEventFactory;
use App\Services\AI\FilmOS\Narrative\Scene\CameraAngle;
use App\Services\AI\FilmOS\Narrative\Scene\CameraConfiguration;
use App\Services\AI\FilmOS\Narrative\Scene\CameraMovement;
use App\Services\AI\FilmOS\Narrative\Scene\LensType;
use App\Services\AI\FilmOS\Narrative\Scene\CameraConfiguredHandler;
use App\Services\AI\FilmOS\Narrative\Scene\SceneEventFactory;
use App\Services\AI\FilmOS\Narrative\Scene\SceneNode;
use App\Services\AI\FilmOS\Narrative\Scene\SceneNodePlacedHandler;
use App\Services\AI\FilmOS\Narrative\Scene\SceneNodeRemovedHandler;
use App\Services\AI\FilmOS\Narrative\Scene\SceneNodeType;
use App\Services\AI\FilmOS\Narrative\Scene\SceneRelation;
use App\Services\AI\FilmOS\Narrative\Scene\SceneRelationEstablishedHandler;
use App\Services\AI\FilmOS\Narrative\Scene\SceneRelationType;
use App\Services\AI\FilmOS\Narrative\Scene\ShotType;
use App\Services\AI\FilmOS\Narrative\Timeline\Bridge\ShotPlannedEventFactory;
use App\Services\AI\FilmOS\Narrative\Timeline\Bridge\ShotPlannedProjectionHandler;
use App\Services\AI\FilmOS\Narrative\Timeline\DefaultTimelineProjector;
use App\Services\AI\FilmOS\Narrative\Timeline\InMemorySemanticTimeline;
use App\Services\AI\FilmOS\Narrative\Timeline\SystemClock;
use App\Services\AI\FilmOS\Narrative\Timeline\TimelineRecorder;
use App\Services\AI\FilmOS\Narrative\Shared\AttributeBag;
use App\Services\AI\FilmOS\Narrative\World\WorldEventFactory;
use App\Services\AI\FilmOS\Narrative\World\WorldFactAssertedHandler;
use App\Services\AI\FilmOS\Narrative\World\WorldObject;
use App\Services\AI\FilmOS\Narrative\World\WorldObjectPlacedHandler;
use App\Services\AI\FilmOS\Narrative\World\WorldObjectRemovedHandler;
use App\Services\AI\FilmOS\Narrative\World\WorldObjectType;
use App\Services\AI\FilmOS\Planning\GoalNode;
use App\Services\AI\FilmOS\Planning\GoalNodeType;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the full D4 pipeline:
 * setupScene() → Timeline → Projection → NarrativeState::$scene
 */
final class D4PipelineTest extends TestCase
{
    // ── Invariant: scene nodes appear in projection ───────────────────────────

    public function test_setup_scene_projects_nodes_into_narrative_state(): void
    {
        [$timeline, $projector, $bootstrapper] = $this->buildStack();

        $bootstrapper->bootstrap(worldObjects: [], worldFacts: [], goalNodes: [
            'hook' => $this->leaf('hook', 'Opening shot'),
        ]);

        $heroNode  = new SceneNode(id: 'hero',  type: SceneNodeType::SUBJECT,     label: 'Hero');
        $villaNode = new SceneNode(id: 'villa', type: SceneNodeType::BACKGROUND,  label: 'Villa');

        $bootstrapper->setupScene(
            nodes:    [$heroNode, $villaNode],
            relations: [],
            camera:   $this->camera(ShotType::ESTABLISHING),
            ordinal:  0,
        );

        $state = $projector->project($timeline);

        $this->assertTrue($state->scene->hasNode('hero'));
        $this->assertTrue($state->scene->hasNode('villa'));
        $this->assertCount(2, $state->scene->allNodes());
    }

    // ── Invariant: camera config is indexed by shot ordinal ───────────────────

    public function test_camera_config_is_indexed_by_shot_ordinal(): void
    {
        [$timeline, $projector, $bootstrapper] = $this->buildStack();

        $bootstrapper->bootstrap(worldObjects: [], worldFacts: [], goalNodes: [
            'hook' => $this->leaf('hook', 'Hook'),
            'body' => $this->leaf('body', 'Body'),
        ]);

        $cam0 = $this->camera(ShotType::ESTABLISHING);
        $cam1 = $this->camera(ShotType::CLOSE_UP, focusNodeId: 'hero');

        $bootstrapper->setupScene(nodes: [], relations: [], camera: $cam0, ordinal: 0);
        $bootstrapper->setupScene(nodes: [], relations: [], camera: $cam1, ordinal: 1);

        $state = $projector->project($timeline);

        $this->assertSame($cam0, $state->scene->getCamera(0));
        $this->assertSame($cam1, $state->scene->getCamera(1));
        $this->assertCount(2, $state->scene->allCameras());
    }

    // ── Invariant: focusNodeId is preserved on camera ─────────────────────────

    public function test_camera_focus_node_id_is_preserved(): void
    {
        [$timeline, $projector, $bootstrapper] = $this->buildStack();

        $bootstrapper->bootstrap(worldObjects: [], worldFacts: [], goalNodes: [
            's0' => $this->leaf('s0', 'Shot 0'),
        ]);

        $cam = new CameraConfiguration(
            shotType:    ShotType::CLOSE_UP,
            angle:       CameraAngle::LOW,
            movement:    CameraMovement::TRACKING,
            lens:        LensType::TELEPHOTO,
            focusNodeId: 'hero',
        );

        $bootstrapper->setupScene(nodes: [], relations: [], camera: $cam, ordinal: 0);

        $state = $projector->project($timeline);

        $this->assertSame('hero', $state->scene->getCamera(0)?->focusNodeId);
    }

    // ── Invariant: scene relations appear in projection ───────────────────────

    public function test_scene_relations_appear_in_projection(): void
    {
        [$timeline, $projector, $bootstrapper] = $this->buildStack();

        $bootstrapper->bootstrap(worldObjects: [], worldFacts: [], goalNodes: [
            's0' => $this->leaf('s0', 'Shot 0'),
        ]);

        $heroNode = new SceneNode(id: 'hero', type: SceneNodeType::SUBJECT, label: 'Hero');
        $camNode  = new SceneNode(id: 'cam',  type: SceneNodeType::CAMERA,  label: 'Camera');
        $targets  = new SceneRelation('cam', 'hero', SceneRelationType::TARGETS);
        $inFrame  = new SceneRelation('hero', 'cam', SceneRelationType::IN_FRAME);

        $bootstrapper->setupScene(
            nodes:    [$heroNode, $camNode],
            relations: [$targets, $inFrame],
            camera:   $this->camera(ShotType::MEDIUM),
            ordinal:  0,
        );

        $state = $projector->project($timeline);

        $this->assertArrayHasKey('cam:hero:targets',  $state->scene->allRelations());
        $this->assertArrayHasKey('hero:cam:in_frame', $state->scene->allRelations());
    }

    // ── Invariant: removed node is absent from projection ─────────────────────

    public function test_removed_scene_node_is_absent_from_projection(): void
    {
        $timeline = new InMemorySemanticTimeline();
        $recorder = new TimelineRecorder($timeline);
        $factory  = new SceneEventFactory(new SystemClock());

        $hero = new SceneNode(id: 'hero', type: SceneNodeType::SUBJECT, label: 'Hero');

        $recorder->appendMany(...$factory->setupShot([$hero], [], $this->camera(ShotType::WIDE), ordinal: 0));
        $recorder->appendMany(...$factory->removeNodes(['hero'], ordinal: 1));

        [, $projector] = $this->buildStack();
        $state = $projector->project($timeline);

        $this->assertFalse($state->scene->hasNode('hero'));
    }

    // ── Invariant: D0 + D3 + D4 coexist in one projection ────────────────────

    public function test_d0_d3_and_d4_coexist_in_same_projection(): void
    {
        [$timeline, $projector, $bootstrapper] = $this->buildStack();

        $heroWorldObject = new WorldObject(
            id: 'hero', type: WorldObjectType::CHARACTER,
            label: 'Hero', attributes: AttributeBag::empty(),
        );

        $bootstrapper->bootstrap(
            worldObjects: [$heroWorldObject],
            worldFacts:   [],
            goalNodes:    ['s0' => $this->leaf('s0', 'Shot 0')],
        );

        $heroSceneNode = new SceneNode(id: 'hero_node', type: SceneNodeType::SUBJECT, label: 'Hero');

        $bootstrapper->setupScene(
            nodes:    [$heroSceneNode],
            relations: [],
            camera:   $this->camera(ShotType::CLOSE_UP, focusNodeId: 'hero_node'),
            ordinal:  0,
        );

        $state = $projector->project($timeline);

        $this->assertCount(1, $state->story->allShots());                              // D0
        $this->assertTrue($state->world->hasObject('hero'));                      // D3
        $this->assertTrue($state->scene->hasNode('hero_node'));                   // D4
        $this->assertSame(ShotType::CLOSE_UP, $state->scene->getCamera(0)?->shotType); // D4
    }

    // ── Invariant: duplicate SceneNodePlaced → exactly 1 node ────────────────

    public function test_duplicate_placed_events_result_in_single_node(): void
    {
        $timeline = new InMemorySemanticTimeline();
        $recorder = new TimelineRecorder($timeline);
        $factory  = new SceneEventFactory(new SystemClock());

        $hero = new SceneNode(id: 'hero', type: SceneNodeType::SUBJECT, label: 'Hero');

        $recorder->appendMany(...$factory->setupShot([$hero], [], $this->camera(ShotType::MEDIUM), ordinal: 0));
        $recorder->appendMany(...$factory->setupShot([$hero], [], $this->camera(ShotType::MEDIUM), ordinal: 0));

        [, $projector] = $this->buildStack();
        $state = $projector->project($timeline);

        $this->assertCount(1, $state->scene->allNodes(),
            'upsertSceneNode must be idempotent — duplicate Placed events must not create duplicates.'
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @return array{InMemorySemanticTimeline, DefaultTimelineProjector, NarrativeBootstrapper} */
    private function buildStack(): array
    {
        $timeline = new InMemorySemanticTimeline();
        $clock    = new SystemClock();

        $projector = new DefaultTimelineProjector(handlers: [
            new ShotPlannedProjectionHandler(),
            new WorldObjectPlacedHandler(),
            new WorldObjectRemovedHandler(),
            new WorldFactAssertedHandler(),
            new SceneNodePlacedHandler(),
            new SceneNodeRemovedHandler(),
            new SceneRelationEstablishedHandler(),
            new CameraConfiguredHandler(),
        ]);

        $bootstrapper = new NarrativeBootstrapper(
            worldFactory:     new WorldEventFactory($clock),
            shotFactory:      new ShotPlannedEventFactory(),
            sceneFactory:     new SceneEventFactory($clock),
            characterFactory:  new CharacterEventFactory($clock),
            productionFactory: new ProductionEventFactory($clock),
            recorder:         new TimelineRecorder($timeline),
        );

        return [$timeline, $projector, $bootstrapper];
    }

    private function leaf(string $id, string $description): GoalNode
    {
        return new GoalNode(id: $id, description: $description, type: GoalNodeType::LEAF, priority: 1.0);
    }

    private function camera(ShotType $shotType, ?string $focusNodeId = null): CameraConfiguration
    {
        return new CameraConfiguration(
            shotType:    $shotType,
            angle:       CameraAngle::EYE_LEVEL,
            movement:    CameraMovement::STATIC,
            lens:        LensType::NORMAL,
            focusNodeId: $focusNodeId,
        );
    }
}
