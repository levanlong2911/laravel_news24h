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
use App\Services\AI\FilmOS\Narrative\Timeline\Projection\SceneProjection;
use PHPUnit\Framework\TestCase;

final class SceneProjectionTest extends TestCase
{
    // ── hasNode ───────────────────────────────────────────────────────────────

    public function test_has_node_returns_true_for_existing_id(): void
    {
        $node       = $this->node('hero');
        $projection = new SceneProjection(nodes: ['hero' => $node]);

        $this->assertTrue($projection->hasNode('hero'));
        $this->assertFalse($projection->hasNode('villain'));
    }

    // ── getCamera ─────────────────────────────────────────────────────────────

    public function test_get_camera_returns_null_for_unknown_ordinal(): void
    {
        $projection = new SceneProjection();

        $this->assertNull($projection->getCamera(0));
        $this->assertNull($projection->getCamera(99));
    }

    public function test_get_camera_returns_config_for_known_ordinal(): void
    {
        $cam        = $this->camera(ShotType::CLOSE_UP);
        $projection = new SceneProjection(cameras: [0 => $cam]);

        $this->assertSame($cam, $projection->getCamera(0));
        $this->assertNull($projection->getCamera(1));
    }

    public function test_multiple_shot_cameras_are_indexed_independently(): void
    {
        $cam0 = $this->camera(ShotType::ESTABLISHING);
        $cam1 = $this->camera(ShotType::CLOSE_UP);
        $cam2 = $this->camera(ShotType::TWO_SHOT);

        $projection = new SceneProjection(cameras: [0 => $cam0, 1 => $cam1, 2 => $cam2]);

        $this->assertSame($cam0, $projection->getCamera(0));
        $this->assertSame($cam1, $projection->getCamera(1));
        $this->assertSame($cam2, $projection->getCamera(2));
    }

    // ── allNodes ──────────────────────────────────────────────────────────────

    public function test_all_nodes_returns_keyed_map(): void
    {
        $hero = $this->node('hero');
        $door = $this->node('door');
        $proj = new SceneProjection(nodes: ['hero' => $hero, 'door' => $door]);

        $this->assertSame(['hero' => $hero, 'door' => $door], $proj->allNodes());
    }

    // ── allRelations ──────────────────────────────────────────────────────────

    public function test_all_relations_returns_keyed_map(): void
    {
        $rel  = new SceneRelation('camera', 'hero', SceneRelationType::TARGETS);
        $key  = 'camera:hero:targets';
        $proj = new SceneProjection(relations: [$key => $rel]);

        $this->assertSame([$key => $rel], $proj->allRelations());
    }

    // ── allCameras ────────────────────────────────────────────────────────────

    public function test_all_cameras_returns_ordinal_keyed_map(): void
    {
        $cam0 = $this->camera(ShotType::WIDE);
        $cam3 = $this->camera(ShotType::INSERT);
        $proj = new SceneProjection(cameras: [0 => $cam0, 3 => $cam3]);

        $this->assertSame([0 => $cam0, 3 => $cam3], $proj->allCameras());
    }

    // ── empty projection is valid ─────────────────────────────────────────────

    public function test_empty_projection_is_valid(): void
    {
        $proj = new SceneProjection();

        $this->assertEmpty($proj->allNodes());
        $this->assertEmpty($proj->allRelations());
        $this->assertEmpty($proj->allCameras());
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function node(string $id): SceneNode
    {
        return new SceneNode(id: $id, type: SceneNodeType::SUBJECT, label: ucfirst($id));
    }

    private function camera(ShotType $shotType): CameraConfiguration
    {
        return new CameraConfiguration(
            shotType: $shotType,
            angle:    CameraAngle::EYE_LEVEL,
            movement: CameraMovement::STATIC,
            lens:     LensType::NORMAL,
        );
    }
}
