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
 * không chồng, giữ thứ tự. Nó KHÔNG được trả lời "scene nào đáng xem hơn" —
 * pacing là taste, thuộc Editorial (Phase 5). Nếu Timeline cân thời lượng theo
 * importance thì nó đã thôi làm scheduler và trở thành editor.
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

    // ---- Cơ học: chia đều, KHÔNG theo importance ----

    public function test_divides_equally_regardless_of_purpose(): void
    {
        // Scene ESTABLISH và scene DETAIL phải cùng thời lượng: Timeline không
        // biết cái nào "đáng xem hơn". Nếu ESTABLISH dài hơn thì đó là pacing —
        // sai tầng, đó là việc Editorial.
        $scenes = new IntentSceneGraph([
            $this->intentScene(1, ScenePurpose::Establish),
            $this->intentScene(2, ScenePurpose::Detail),
            $this->intentScene(3, ScenePurpose::Resolution),
        ]);

        $timed = (new TimelinePlanner())->plan($scenes, 30.0)->scenes;

        $d0 = $timed[0]->time->duration();
        $d1 = $timed[1]->time->duration();
        $d2 = $timed[2]->time->duration();

        $this->assertEqualsWithDelta($d0, $d1, 1e-9);
        $this->assertEqualsWithDelta($d1, $d2, 1e-9);
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
