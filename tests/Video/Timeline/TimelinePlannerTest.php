<?php

namespace Tests\Video\Timeline;

use App\Video\Intent\CameraFraming;
use App\Video\Intent\CameraIntent;
use App\Video\Intent\CameraMovement;
use App\Video\Intent\CameraSpeed;
use App\Video\Intent\IntentScene;
use App\Video\Intent\IntentSceneGraph;
use App\Video\Intent\MotionIntent;
use App\Video\Scene\ScenePurpose;
use App\Video\Scene\SemanticScene;
use App\Video\Timeline\TimelinePlanner;
use PHPUnit\Framework\TestCase;

/**
 * Timeline là SCHEDULER cơ học, không phải EDITOR.
 *
 * Nó chỉ giải: N scene, T giây → mỗi scene [start, end], phủ kín, không hở,
 * không chồng, giữ thứ tự. Nó KHÔNG tự PHÁT MINH pacing — không đọc World
 * Graph, không đọc importance của Act, không có "gu" riêng.
 *
 * 2026-07-23 (Phase 5 đã hẹn trước): pacing-theo-ScenePurpose giờ CÓ, nhưng
 * trọng số đến từ `EditorialInterpreter::durationWeightFor()` (cùng "mù World
 * Graph" như aestheticFor()) — Timeline chỉ CHIA theo trọng số đã cho, không
 * tự quyết trọng số nào đúng. Ranh giới scheduler/editor vẫn giữ, chỉ input
 * phong phú hơn (list trọng số thay vì đếm số scene).
 */
class TimelinePlannerTest extends TestCase
{
    private function intentScene(int $ordinal, ScenePurpose $purpose = ScenePurpose::Detail): IntentScene
    {
        $scene  = new SemanticScene("scene_{$ordinal}", 'act_1', $ordinal, $purpose, ['x']);
        $camera = new CameraIntent(CameraFraming::Wide, CameraMovement::Static, CameraSpeed::Slow, 'x');

        return new IntentScene($scene, $camera, MotionIntent::Low);
    }

    private function graph(int $n): IntentSceneGraph
    {
        $scenes = [];
        for ($i = 1; $i <= $n; $i++) {
            $scenes[] = $this->intentScene($i);
        }

        return new IntentSceneGraph($scenes);
    }

    private function plan(int $n, float $target = 60.0): array
    {
        return (new TimelinePlanner())->plan($this->graph($n), $target)->scenes;
    }

    // ---- Invariant của scheduler ----

    public function test_starts_at_zero(): void
    {
        $timed = $this->plan(9);

        $this->assertSame(0.0, $timed[0]->time->start);
    }

    public function test_ends_exactly_at_target(): void
    {
        $timed = $this->plan(9, 60.0);

        $this->assertSame(60.0, end($timed)->time->end, 'phải phủ kín target, không thiếu một phần nghìn giây');
    }

    public function test_is_gapless_and_non_overlapping(): void
    {
        $timed = $this->plan(7, 45.0);

        foreach (array_slice($timed, 1) as $i => $scene) {
            $this->assertSame(
                $timed[$i]->time->end,
                $scene->time->start,
                'end của scene trước phải TRÙNG KHỚP start của scene sau — không hở, không chồng',
            );
        }
    }

    public function test_every_range_has_positive_length(): void
    {
        foreach ($this->plan(9) as $scene) {
            $this->assertGreaterThan($scene->time->start, $scene->time->end);
        }
    }

    public function test_preserves_scene_order(): void
    {
        $timed = $this->plan(5);

        $this->assertSame([1, 2, 3, 4, 5], array_map(fn ($t) => $t->intent->scene->ordinal, $timed));
    }

    public function test_is_deterministic(): void
    {
        $a = array_map(fn ($t) => [$t->time->start, $t->time->end], $this->plan(9));
        $b = array_map(fn ($t) => [$t->time->start, $t->time->end], $this->plan(9));

        $this->assertSame($a, $b);
    }

    public function test_empty_graph_yields_empty_timeline(): void
    {
        $timed = (new TimelinePlanner())->plan(new IntentSceneGraph(), 60.0)->scenes;

        $this->assertSame([], $timed);
    }

    // ---- Cơ học: chia theo trọng số Editorial, KHÔNG tự phát minh pacing ----

    public function test_same_purpose_scenes_still_split_equally(): void
    {
        // Cùng ScenePurpose -> cùng trọng số -> vẫn chia đều giữa CHÚNG với
        // nhau. Không phải "luôn chia đều tuyệt đối" như trước — mà "chia đều
        // khi trọng số bằng nhau", đúng hệ quả của việc chia theo tỉ lệ.
        $timed = $this->plan(3, 30.0); // mặc định toàn ScenePurpose::Detail

        $this->assertEqualsWithDelta($timed[0]->time->duration(), $timed[1]->time->duration(), 1e-9);
        $this->assertEqualsWithDelta($timed[1]->time->duration(), $timed[2]->time->duration(), 1e-9);
    }

    public function test_action_scene_gets_more_time_than_detail_scene(): void
    {
        // Đúng bằng chứng EditorialInterpreter::durationWeightFor(): Action
        // (1.4) > Detail (0.7) — Action phải được thời lượng dài hơn.
        $scenes = new IntentSceneGraph([
            $this->intentScene(1, ScenePurpose::Action),
            $this->intentScene(2, ScenePurpose::Detail),
        ]);

        $timed = (new TimelinePlanner())->plan($scenes, 30.0)->scenes;

        $this->assertGreaterThan($timed[1]->time->duration(), $timed[0]->time->duration());
    }

    public function test_weighted_durations_still_cover_target_exactly(): void
    {
        // Trọng số khác nhau KHÔNG được phá bất biến gapless/phủ kín target.
        $scenes = new IntentSceneGraph([
            $this->intentScene(1, ScenePurpose::Establish),
            $this->intentScene(2, ScenePurpose::Action),
            $this->intentScene(3, ScenePurpose::Detail),
            $this->intentScene(4, ScenePurpose::Resolution),
        ]);

        $timed = (new TimelinePlanner())->plan($scenes, 47.0)->scenes;

        $this->assertSame(0.0, $timed[0]->time->start);
        $this->assertSame(47.0, end($timed)->time->end);
        foreach (array_slice($timed, 1) as $i => $scene) {
            $this->assertSame($timed[$i]->time->end, $scene->time->start);
        }
    }

    public function test_duration_is_derived_from_range(): void
    {
        $timed = $this->plan(4, 40.0);

        foreach ($timed as $scene) {
            $this->assertEqualsWithDelta(
                $scene->time->end - $scene->time->start,
                $scene->time->duration(),
                1e-9,
            );
        }
    }
}
