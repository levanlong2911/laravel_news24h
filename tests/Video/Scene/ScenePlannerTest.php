<?php

namespace Tests\Video\Scene;

use App\Video\Evidence\Evidence;
use App\Video\Evidence\EvidenceSource;
use App\Video\Evidence\ProvenanceLevel;
use App\Video\Scene\ScenePlanner;
use App\Video\Scene\ScenePurpose;
use App\Video\Story\StoryPlanner;
use App\Video\World\Entity;
use App\Video\World\EntityType;
use App\Video\World\Event;
use App\Video\World\Identity;
use App\Video\World\Relation;
use App\Video\World\VerifiedAttribute;
use App\Video\World\VerifiedWorldGraph;
use PHPUnit\Framework\TestCase;

class ScenePlannerTest extends TestCase
{
    private function ev(string $q = 'x'): Evidence
    {
        return new Evidence($q, EvidenceSource::Body, 0, ProvenanceLevel::Direct);
    }

    private function attr(string $name, mixed $value): array
    {
        return [new VerifiedAttribute($name, $value, $this->ev(), ProvenanceLevel::Direct)];
    }

    private function moonriseGraph(): VerifiedWorldGraph
    {
        return new VerifiedWorldGraph(
            [
                new Entity('moonrise_2020', EntityType::Vehicle, [
                    'length_meters'   => $this->attr('length_meters', 99.95),
                    'hull_color'      => $this->attr('hull_color', 'grey'),
                    'top_speed_knots' => $this->attr('top_speed_knots', 19.5),
                    'guest_capacity'  => $this->attr('guest_capacity', 16),
                ], new Identity('Moonrise', true, $this->ev())),
                new Entity('jan_koum', EntityType::Human, [
                    'occupation' => $this->attr('occupation', 'founder of WhatsApp'),
                ], new Identity('Jan Koum', false, $this->ev())),
                new Entity('feadship', EntityType::Building, [], new Identity('Feadship', true, $this->ev())),
            ],
            [
                new Relation('r1', 'feadship', 'moonrise_2020', 'built', $this->ev()),
                new Relation('r2', 'jan_koum', 'moonrise_2020', 'original_owner', $this->ev()),
            ],
            [new Event('e1', 'sale', 'moonrise_2020', $this->ev())],
        );
    }

    private function scenes(VerifiedWorldGraph $graph, int $detailThreshold = 4): array
    {
        $story = (new StoryPlanner())->plan($graph);

        return (new ScenePlanner($detailThreshold))->plan($story, $graph)->scenes;
    }

    // ---- Bất biến ----

    public function test_every_act_yields_at_least_one_scene(): void
    {
        $graph = $this->moonriseGraph();
        $story = (new StoryPlanner())->plan($graph);
        $scenes = (new ScenePlanner())->plan($story, $graph)->scenes;

        $actIds = array_map(fn ($a) => $a->id, $story->acts);
        $covered = array_unique(array_map(fn ($s) => $s->actId, $scenes));

        $this->assertEqualsCanonicalizing($actIds, $covered, 'mọi act phải có ít nhất một scene');
        $this->assertGreaterThanOrEqual(count($actIds), count($scenes));
    }

    public function test_ordinals_are_contiguous_from_one(): void
    {
        $scenes = $this->scenes($this->moonriseGraph());

        foreach ($scenes as $i => $scene) {
            $this->assertSame($i + 1, $scene->ordinal);
        }
    }

    public function test_every_scene_has_at_least_one_subject_that_exists(): void
    {
        $graph = $this->moonriseGraph();

        foreach ($this->scenes($graph) as $scene) {
            $this->assertNotEmpty($scene->subjectIds);
            foreach ($scene->subjectIds as $id) {
                $this->assertTrue($graph->hasEntity($id), "scene {$scene->id} trỏ tới entity không tồn tại: {$id}");
            }
        }
    }

    public function test_is_deterministic(): void
    {
        $graph = $this->moonriseGraph();

        $a = array_map(fn ($s) => $s->id . $s->purpose->value, $this->scenes($graph));
        $b = array_map(fn ($s) => $s->id . $s->purpose->value, $this->scenes($graph));

        $this->assertSame($a, $b);
    }

    // ---- Decomposition driven bởi độ giàu graph, không phải chủ đề ----

    public function test_attribute_rich_introduce_act_splits_into_establish_and_detail(): void
    {
        // moonrise_2020 có 4 thuộc tính (>= threshold) → toàn cảnh + cận cảnh.
        $scenes = $this->scenes($this->moonriseGraph(), detailThreshold: 4);

        $this->assertSame(ScenePurpose::Establish, $scenes[0]->purpose);
        $this->assertSame(ScenePurpose::Detail, $scenes[1]->purpose);
        $this->assertSame($scenes[0]->actId, $scenes[1]->actId, 'hai cảnh cùng một act');
        $this->assertSame(['moonrise_2020'], $scenes[0]->subjectIds);
    }

    public function test_a_thin_introduce_entity_gets_a_single_scene(): void
    {
        // Nâng threshold lên 99: không entity nào đủ giàu → introduce chỉ 1 cảnh.
        $scenes = $this->scenes($this->moonriseGraph(), detailThreshold: 99);

        $this->assertSame(ScenePurpose::Establish, $scenes[0]->purpose);
        $this->assertNotSame($scenes[0]->actId, $scenes[1]->actId, 'introduce chỉ một cảnh nên act đổi ngay');
    }

    // ---- purpose suy cơ học từ (role, source) ----

    public function test_event_act_becomes_an_action_scene(): void
    {
        $scenes = $this->scenes($this->moonriseGraph());

        $eventScene = array_values(array_filter($scenes, fn ($s) => $s->purpose === ScenePurpose::Action));

        $this->assertNotEmpty($eventScene, 'act EVENT phải cho scene ACTION');
        $this->assertSame(['moonrise_2020'], $eventScene[0]->subjectIds, 'subject của event là entity của nó');
    }

    public function test_relation_act_becomes_a_comparison_scene_with_both_endpoints(): void
    {
        $scenes = $this->scenes($this->moonriseGraph());

        $relScene = array_values(array_filter($scenes, fn ($s) => $s->purpose === ScenePurpose::Comparison));

        $this->assertNotEmpty($relScene, 'act RELATION phải cho scene COMPARISON');
        $this->assertCount(2, $relScene[0]->subjectIds, 'quan hệ khắc hoạ cả hai đầu');
    }

    public function test_last_scene_resolves(): void
    {
        $scenes = $this->scenes($this->moonriseGraph());

        $this->assertSame(ScenePurpose::Resolution, end($scenes)->purpose);
    }

    // ---- Ontology giữ vững khi đổi chủ đề ----

    public function test_same_planner_handles_a_different_topic(): void
    {
        $graph = new VerifiedWorldGraph(
            [
                new Entity('lion', EntityType::LivingObject, [
                    'mane_color' => $this->attr('mane_color', 'golden'),
                    'weight_kg'  => $this->attr('weight_kg', 190),
                ], new Identity('Panthera leo', true, $this->ev())),
                new Entity('savannah', EntityType::Landscape, ['grass' => $this->attr('grass', 'dry')]),
            ],
            [new Relation('r1', 'lion', 'savannah', 'hunts_in', $this->ev())],
            [new Event('e1', 'hunt', 'lion', $this->ev())],
        );

        $scenes = $this->scenes($graph);

        $this->assertNotEmpty($scenes);
        $this->assertSame(['lion'], $scenes[0]->subjectIds);
        $this->assertSame(ScenePurpose::Establish, $scenes[0]->purpose);
    }

    public function test_empty_story_yields_no_scenes(): void
    {
        $scenes = (new ScenePlanner())->plan(new \App\Video\Story\StoryGraph(), new VerifiedWorldGraph())->scenes;

        $this->assertSame([], $scenes);
    }
}
