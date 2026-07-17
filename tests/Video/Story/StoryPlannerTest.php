<?php

namespace Tests\Video\Story;

use App\Video\Evidence\Evidence;
use App\Video\Evidence\EvidenceSource;
use App\Video\Evidence\ProvenanceLevel;
use App\Video\Story\ActSource;
use App\Video\Story\NarrativeRole;
use App\Video\Story\StoryPlanner;
use App\Video\World\Entity;
use App\Video\World\EntityType;
use App\Video\World\Event;
use App\Video\World\Identity;
use App\Video\World\Relation;
use App\Video\World\VerifiedAttribute;
use App\Video\World\VerifiedWorldGraph;
use PHPUnit\Framework\TestCase;

/**
 * Bài kiểm tra đầu tiên của triết lý "ontology thay cho topic".
 *
 * Các test dưới đây KHÔNG assert "Moonrise phải sinh ra act Luxury". Chúng
 * assert BẤT BIẾN của graph. Lý do: "Luxury" không tồn tại trong ontology — nó
 * là cách con người nhóm thuộc tính lại. Muốn Planner sinh ra cái tên đó, nó
 * phải biết beach club + spa = sự xa hoa, tức là domain knowledge trong code.
 *
 * Cũng không assert act_count == 6: nếu mai Extractor recall tốt hơn và tìm ra
 * successor_of, bài Moonrise thành 7 act mà Planner vẫn hoàn toàn đúng. Assert
 * số act là đang khoá recall bằng một test sai chỗ.
 */
class StoryPlannerTest extends TestCase
{
    private function evidence(string $quote = 'x'): Evidence
    {
        return new Evidence($quote, EvidenceSource::Body, 0, ProvenanceLevel::Direct);
    }

    private function attr(string $name, mixed $value): array
    {
        return [new VerifiedAttribute($name, $value, $this->evidence(), ProvenanceLevel::Direct)];
    }

    /**
     * Graph rút gọn từ cú trích xuất Moonrise THẬT (2026-07-17).
     * moonrise_2020 nổi lên đầu không phải vì ai bảo "yacht là chủ đề chính",
     * mà vì 24 thuộc tính + 7 quan hệ chạm vào nó.
     */
    private function moonriseGraph(): VerifiedWorldGraph
    {
        $entities = [
            new Entity('moonrise_2020', EntityType::Vehicle, [
                'length_meters'   => $this->attr('length_meters', 99.95),
                'hull_color'      => $this->attr('hull_color', 'grey'),
                'top_speed_knots' => $this->attr('top_speed_knots', 19.5),
                'guest_capacity'  => $this->attr('guest_capacity', 16),
            ], new Identity('Moonrise', true, $this->evidence('Moonrise'))),

            new Entity('moonrise_2025', EntityType::Vehicle, [
                'length_meters' => $this->attr('length_meters', 101),
            ], new Identity('Moonrise', true, $this->evidence('Moonrise'))),

            new Entity('jan_koum', EntityType::Human, [
                'occupation' => $this->attr('occupation', 'founder of WhatsApp'),
            ], new Identity('Jan Koum', false, $this->evidence('Jan Koum'))),

            // Anchor-only: có tên, không thuộc tính. Vẫn là node hợp lệ.
            new Entity('feadship', EntityType::Building, [], new Identity('Feadship', true, $this->evidence('Feadship'))),
        ];

        $relations = [
            new Relation('r1', 'feadship', 'moonrise_2020', 'built', $this->evidence()),
            new Relation('r2', 'jan_koum', 'moonrise_2020', 'original_owner', $this->evidence()),
            new Relation('r3', 'feadship', 'moonrise_2025', 'built', $this->evidence()),
        ];

        $events = [
            new Event('e1', 'sale', 'moonrise_2020', $this->evidence()),
            new Event('e2', 'delivery', 'moonrise_2020', $this->evidence()),
        ];

        return new VerifiedWorldGraph($entities, $relations, $events);
    }

    // ---- Bất biến của graph ----

    public function test_produces_at_least_one_act(): void
    {
        $story = (new StoryPlanner())->plan($this->moonriseGraph());

        $this->assertGreaterThanOrEqual(1, count($story->acts));
    }

    public function test_every_act_references_a_node_or_edge_that_exists(): void
    {
        $graph = $this->moonriseGraph();
        $story = (new StoryPlanner())->plan($graph);

        $relationIds = array_map(fn (Relation $r) => $r->id, $graph->relations);
        $eventIds    = array_map(fn (Event $e) => $e->id, $graph->events);

        foreach ($story->acts as $act) {
            match ($act->source) {
                ActSource::Entity   => $this->assertTrue($graph->hasEntity($act->sourceId), "act {$act->id} trỏ tới entity không tồn tại"),
                ActSource::Relation => $this->assertContains($act->sourceId, $relationIds),
                ActSource::Event    => $this->assertContains($act->sourceId, $eventIds),
            };
        }
    }

    public function test_importance_is_monotonically_decreasing(): void
    {
        $acts = (new StoryPlanner())->plan($this->moonriseGraph())->acts;

        foreach (array_slice($acts, 1) as $i => $act) {
            $this->assertLessThanOrEqual($acts[$i]->importance, $act->importance);
        }
    }

    public function test_ordinals_are_contiguous_from_one(): void
    {
        $acts = (new StoryPlanner())->plan($this->moonriseGraph())->acts;

        foreach ($acts as $i => $act) {
            $this->assertSame($i + 1, $act->ordinal);
        }
    }

    /**
     * moonrise_2020 lên đầu vì cấu trúc graph, không vì ai gõ 'yacht' vào code.
     */
    public function test_the_most_connected_node_leads(): void
    {
        $acts = (new StoryPlanner())->plan($this->moonriseGraph())->acts;

        $this->assertSame(ActSource::Entity, $acts[0]->source);
        $this->assertSame('moonrise_2020', $acts[0]->sourceId);
    }

    public function test_is_deterministic(): void
    {
        $planner = new StoryPlanner();
        $graph   = $this->moonriseGraph();

        $first  = array_map(fn ($a) => $a->id, $planner->plan($graph)->acts);
        $second = array_map(fn ($a) => $a->id, $planner->plan($graph)->acts);

        $this->assertSame($first, $second);
    }

    public function test_empty_graph_yields_no_acts(): void
    {
        $story = (new StoryPlanner())->plan(new VerifiedWorldGraph());

        $this->assertSame([], $story->acts);
    }

    // ---- narrative_role là ontology, không phải domain ----

    public function test_first_act_introduces_and_last_resolves(): void
    {
        $acts = (new StoryPlanner())->plan($this->moonriseGraph())->acts;

        $this->assertSame(NarrativeRole::Introduce, $acts[0]->role);
        $this->assertSame(NarrativeRole::Resolve, end($acts)->role);
    }

    public function test_middle_acts_explain(): void
    {
        $acts = (new StoryPlanner())->plan($this->moonriseGraph())->acts;

        foreach (array_slice($acts, 1, -1) as $act) {
            $this->assertSame(NarrativeRole::Explain, $act->role);
        }
    }

    // ---- Ontology giữ vững khi đổi chủ đề ----

    /**
     * Cùng một đoạn code, không sửa một dòng nào. Nếu test này cần thay đổi
     * Planner thì triết lý "ontology thay cho topic" đã sụp.
     */
    public function test_same_planner_handles_a_completely_different_topic(): void
    {
        $graph = new VerifiedWorldGraph(
            [
                new Entity('lion', EntityType::LivingObject, [
                    'mane_color' => $this->attr('mane_color', 'golden'),
                    'weight_kg'  => $this->attr('weight_kg', 190),
                ], new Identity('Panthera leo', true, $this->evidence())),
                new Entity('savannah', EntityType::Landscape, [
                    'grass' => $this->attr('grass', 'dry'),
                ]),
            ],
            [new Relation('r1', 'lion', 'savannah', 'hunts_in', $this->evidence())],
            [new Event('e1', 'hunt', 'lion', $this->evidence())],
        );

        $acts = (new StoryPlanner())->plan($graph)->acts;

        $this->assertGreaterThanOrEqual(1, count($acts));
        $this->assertSame('lion', $acts[0]->sourceId);
        $this->assertSame(NarrativeRole::Introduce, $acts[0]->role);
    }

    public function test_anchor_only_entities_can_still_become_acts(): void
    {
        // Feadship không có thuộc tính nào nhưng neo 2 quan hệ — nó vẫn đáng kể.
        $acts = (new StoryPlanner(maxActs: 20))->plan($this->moonriseGraph())->acts;

        $ids = array_map(fn ($a) => $a->sourceId, $acts);

        $this->assertContains('feadship', $ids);
    }

    public function test_max_acts_is_respected(): void
    {
        $acts = (new StoryPlanner(maxActs: 3))->plan($this->moonriseGraph())->acts;

        $this->assertCount(3, $acts);
    }

    /**
     * Act = node|edge. Implementation phải sinh được cả ba loại, không chỉ
     * entity — nếu không thì code lệch contract §3 và Phase sau phải mở lại mô
     * hình. Với maxActs đủ lớn, graph Moonrise (4 entity, 2 event, 3 relation)
     * phải cho ra act từ cả ba nguồn.
     */
    public function test_events_and_relations_also_become_acts(): void
    {
        $acts = (new StoryPlanner(maxActs: 20))->plan($this->moonriseGraph())->acts;

        $sources = array_map(fn ($a) => $a->source, $acts);

        $this->assertContains(ActSource::Entity, $sources);
        $this->assertContains(ActSource::Event, $sources, 'sự kiện phải sinh được act');
        $this->assertContains(ActSource::Relation, $sources, 'quan hệ phải sinh được act');
    }
}
