<?php

namespace Tests\Video\Intent;

use App\Video\Intent\CameraFraming;
use App\Video\Intent\CameraMovement;
use App\Video\Intent\IntentPlanner;
use App\Video\Intent\MotionIntent;
use App\Video\Scene\SceneGraph;
use App\Video\Scene\ScenePurpose;
use App\Video\Scene\SemanticScene;
use PHPUnit\Framework\TestCase;

/**
 * Intent Planner: SceneGraph → camera + motion intent.
 *
 * Ranh giới được TYPE SYSTEM bảo đảm, không phải grep: IntentPlanner chỉ nhận
 * SceneGraph, KHÔNG nhận VerifiedWorldGraph. Nó không thấy EntityType nào — nên
 * camera KHÔNG THỂ phụ thuộc chủ đề kể cả muốn. Camera grammar suy thuần từ
 * ScenePurpose. "Establishing shot thì wide" đúng cho yacht, sư tử, nhà máy.
 */
class IntentPlannerTest extends TestCase
{
    private function scene(ScenePurpose $purpose, array $subjects = ['x'], int $ordinal = 1): SemanticScene
    {
        return new SemanticScene("scene_{$ordinal}", 'act_1', $ordinal, $purpose, $subjects);
    }

    private function planOne(ScenePurpose $purpose, array $subjects = ['x']): \App\Video\Intent\IntentScene
    {
        return (new IntentPlanner())->plan(new SceneGraph([$this->scene($purpose, $subjects)]))->scenes[0];
    }

    // ---- Bất biến ----

    public function test_every_scene_gets_exactly_one_intent_scene(): void
    {
        $graph = new SceneGraph([
            $this->scene(ScenePurpose::Establish, ['a'], 1),
            $this->scene(ScenePurpose::Action, ['b'], 2),
            $this->scene(ScenePurpose::Resolution, ['c'], 3),
        ]);

        $intents = (new IntentPlanner())->plan($graph)->scenes;

        $this->assertCount(3, $intents);
        $this->assertSame([1, 2, 3], array_map(fn ($i) => $i->scene->ordinal, $intents));
    }

    public function test_camera_target_is_the_primary_subject(): void
    {
        $intent = $this->planOne(ScenePurpose::Comparison, ['moonrise_2020', 'moonrise_2025']);

        $this->assertSame('moonrise_2020', $intent->camera->target);
    }

    public function test_is_deterministic(): void
    {
        $graph = new SceneGraph([$this->scene(ScenePurpose::Establish)]);

        $a = (new IntentPlanner())->plan($graph)->scenes[0];
        $b = (new IntentPlanner())->plan($graph)->scenes[0];

        $this->assertEquals($a->camera, $b->camera);
        $this->assertSame($a->motion, $b->motion);
    }

    public function test_empty_scene_graph_yields_no_intents(): void
    {
        $this->assertSame([], (new IntentPlanner())->plan(new SceneGraph())->scenes);
    }

    // ---- Camera grammar suy từ purpose (ngôn ngữ điện ảnh, độc lập chủ đề) ----

    public function test_establishing_shots_are_wide(): void
    {
        $this->assertSame(CameraFraming::Wide, $this->planOne(ScenePurpose::Establish)->camera->framing);
    }

    public function test_detail_shots_are_close(): void
    {
        $framing = $this->planOne(ScenePurpose::Detail)->camera->framing;

        $this->assertContains($framing, [CameraFraming::Close, CameraFraming::Detail]);
    }

    public function test_action_shots_track_with_high_motion(): void
    {
        $intent = $this->planOne(ScenePurpose::Action);

        $this->assertSame(CameraMovement::Track, $intent->camera->movement);
        $this->assertSame(MotionIntent::High, $intent->motion);
    }

    public function test_detail_inspection_is_nearly_static(): void
    {
        // Cận cảnh soi chi tiết cần ít chuyển động — Python sẽ chọn Ken Burns
        // rẻ thay vì Kling. Đây chính là ý nghĩa motion_intent thay content_type.
        $this->assertSame(MotionIntent::None, $this->planOne(ScenePurpose::Detail)->motion);
    }

    /**
     * BÀI TEST QUAN TRỌNG NHẤT của Phase 3: camera phụ thuộc DUY NHẤT vào
     * purpose, không vào subject là gì. Hai establishing scene với subject khác
     * hẳn nhau (một cái tên như yacht, một cái như sư tử) phải cho camera Y HỆT.
     * Nếu test này fail thì camera đã dính chủ đề.
     */
    public function test_camera_depends_only_on_purpose_not_on_subject(): void
    {
        $yachtish = $this->planOne(ScenePurpose::Establish, ['some_vehicle'])->camera;
        $lionish  = $this->planOne(ScenePurpose::Establish, ['some_animal'])->camera;

        $this->assertSame($yachtish->framing, $lionish->framing);
        $this->assertSame($yachtish->movement, $lionish->movement);
        $this->assertSame($yachtish->speed, $lionish->speed);
    }

    public function test_every_purpose_maps_to_a_complete_camera(): void
    {
        // Không purpose nào để lại camera khuyết — mọi cảnh phải quay được.
        foreach (ScenePurpose::cases() as $purpose) {
            $camera = $this->planOne($purpose)->camera;

            $this->assertInstanceOf(CameraFraming::class, $camera->framing);
            $this->assertInstanceOf(CameraMovement::class, $camera->movement);
        }
    }
}
