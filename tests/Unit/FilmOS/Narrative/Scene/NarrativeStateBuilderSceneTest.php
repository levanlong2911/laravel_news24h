<?php

declare(strict_types=1);

namespace Tests\Unit\FilmOS\Narrative\Scene;

use App\Services\AI\FilmOS\Narrative\Scene\CameraAngle;
use App\Services\AI\FilmOS\Narrative\Scene\CameraConfiguration;
use App\Services\AI\FilmOS\Narrative\Scene\CameraMovement;
use App\Services\AI\FilmOS\Narrative\Scene\LensType;
use App\Services\AI\FilmOS\Narrative\Scene\SceneNode;
use App\Services\AI\FilmOS\Narrative\Scene\SceneNodeType;
use App\Services\AI\FilmOS\Narrative\Scene\SceneRelation;
use App\Services\AI\FilmOS\Narrative\Scene\SceneRelationType;
use App\Services\AI\FilmOS\Narrative\Scene\ShotType;
use App\Services\AI\FilmOS\Narrative\Timeline\NarrativeState;
use App\Services\AI\FilmOS\Narrative\Timeline\NarrativeStateBuilder;
use App\Services\AI\FilmOS\Narrative\Timeline\ProjectionMetadata;
use PHPUnit\Framework\TestCase;

final class NarrativeStateBuilderSceneTest extends TestCase
{
    // ── upsertSceneNode ───────────────────────────────────────────────────────

    public function test_upsert_scene_node_stores_node(): void
    {
        $builder = new NarrativeStateBuilder();
        $hero    = $this->node('hero');

        $builder->upsertSceneNode($hero);
        $state = $this->build($builder);

        $this->assertSame($hero, $state->scene->allNodes()['hero']);
    }

    public function test_upsert_scene_node_is_idempotent_on_same_id(): void
    {
        $builder = new NarrativeStateBuilder();
        $v1      = $this->node('camera');
        $v2      = new SceneNode(id: 'camera', type: SceneNodeType::CAMERA, label: 'Main Camera v2');

        $builder->upsertSceneNode($v1);
        $builder->upsertSceneNode($v2);
        $state = $this->build($builder);

        $this->assertSame($v2, $state->scene->allNodes()['camera']);
        $this->assertCount(1, $state->scene->allNodes());
    }

    // ── removeSceneNode ───────────────────────────────────────────────────────

    public function test_remove_scene_node_removes_by_id(): void
    {
        $builder = new NarrativeStateBuilder();
        $builder->upsertSceneNode($this->node('hero'));
        $builder->upsertSceneNode($this->node('villa'));

        $builder->removeSceneNode('hero');
        $state = $this->build($builder);

        $this->assertFalse($state->scene->hasNode('hero'));
        $this->assertTrue($state->scene->hasNode('villa'));
    }

    public function test_remove_nonexistent_node_does_not_fail(): void
    {
        $builder = new NarrativeStateBuilder();
        $builder->removeSceneNode('ghost');
        $state = $this->build($builder);

        $this->assertEmpty($state->scene->allNodes());
    }

    // ── establishSceneRelation ────────────────────────────────────────────────

    public function test_establish_scene_relation_stores_relation(): void
    {
        $builder  = new NarrativeStateBuilder();
        $relation = new SceneRelation('camera', 'hero', SceneRelationType::TARGETS);

        $builder->establishSceneRelation($relation);
        $state = $this->build($builder);

        $this->assertArrayHasKey('camera:hero:targets', $state->scene->allRelations());
        $this->assertSame($relation, $state->scene->allRelations()['camera:hero:targets']);
    }

    public function test_same_pair_can_hold_multiple_relation_types(): void
    {
        $builder = new NarrativeStateBuilder();
        $targets = new SceneRelation('camera', 'hero', SceneRelationType::TARGETS);
        $inFrame = new SceneRelation('camera', 'hero', SceneRelationType::IN_FRAME);

        $builder->establishSceneRelation($targets);
        $builder->establishSceneRelation($inFrame);
        $state = $this->build($builder);

        $this->assertCount(2, $state->scene->allRelations());
        $this->assertArrayHasKey('camera:hero:targets', $state->scene->allRelations());
        $this->assertArrayHasKey('camera:hero:in_frame', $state->scene->allRelations());
    }

    public function test_same_relation_key_is_last_write_wins(): void
    {
        $builder = new NarrativeStateBuilder();
        $r1      = new SceneRelation('cam', 'hero', SceneRelationType::ADJACENT);
        $r2      = new SceneRelation('cam', 'hero', SceneRelationType::ADJACENT);

        $builder->establishSceneRelation($r1);
        $builder->establishSceneRelation($r2);
        $state = $this->build($builder);

        $this->assertCount(1, $state->scene->allRelations());
        $this->assertSame($r2, $state->scene->allRelations()['cam:hero:adjacent']);
    }

    // ── configureCamera ───────────────────────────────────────────────────────

    public function test_configure_camera_stores_by_ordinal(): void
    {
        $builder = new NarrativeStateBuilder();
        $cam     = $this->camera(ShotType::CLOSE_UP);

        $builder->configureCamera(0, $cam);
        $state = $this->build($builder);

        $this->assertSame($cam, $state->scene->getCamera(0));
        $this->assertNull($state->scene->getCamera(1));
    }

    public function test_configure_camera_accumulates_history_across_ordinals(): void
    {
        $builder = new NarrativeStateBuilder();
        $cam0    = $this->camera(ShotType::ESTABLISHING);
        $cam1    = $this->camera(ShotType::CLOSE_UP);
        $cam2    = $this->camera(ShotType::TWO_SHOT);

        $builder->configureCamera(0, $cam0);
        $builder->configureCamera(1, $cam1);
        $builder->configureCamera(2, $cam2);
        $state = $this->build($builder);

        $this->assertSame($cam0, $state->scene->getCamera(0));
        $this->assertSame($cam1, $state->scene->getCamera(1));
        $this->assertSame($cam2, $state->scene->getCamera(2));
        $this->assertCount(3, $state->scene->allCameras());
    }

    public function test_configure_camera_same_ordinal_last_write_wins(): void
    {
        $builder = new NarrativeStateBuilder();
        $v1      = $this->camera(ShotType::WIDE);
        $v2      = $this->camera(ShotType::CLOSE_UP, focusNodeId: 'hero');

        $builder->configureCamera(0, $v1);
        $builder->configureCamera(0, $v2);
        $state = $this->build($builder);

        $this->assertSame($v2, $state->scene->getCamera(0));
        $this->assertCount(1, $state->scene->allCameras());
    }

    // ── D4 does not bleed into other domains ──────────────────────────────────

    public function test_scene_operations_do_not_affect_world_domain(): void
    {
        $builder = new NarrativeStateBuilder();
        $builder->upsertSceneNode($this->node('hero'));
        $state = $this->build($builder);

        $this->assertEmpty($state->world->allObjects(), 'scene nodes must not leak into world domain');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function node(string $id): SceneNode
    {
        return new SceneNode(id: $id, type: SceneNodeType::SUBJECT, label: ucfirst($id));
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

    private function build(NarrativeStateBuilder $builder): NarrativeState
    {
        return $builder->build(
            NarrativeState::SCHEMA_VERSION,
            new ProjectionMetadata(projectionTimeMs: 0, eventCount: 0, lastOrdinal: -1, generatedAt: time()),
        );
    }
}
