<?php

namespace Tests\Video\Editorial;

use App\Video\Editorial\ActionCandidate;
use App\Video\Editorial\ActionType;
use App\Video\Editorial\Composition;
use App\Video\Editorial\EditorialInterpreter;
use App\Video\Editorial\EditorialPolicy;
use App\Video\Editorial\Emotion;
use App\Video\Editorial\LightGrade;
use App\Video\Editorial\LightIntensity;
use App\Video\Editorial\SceneAesthetic;
use App\Video\Evidence\Evidence;
use App\Video\Evidence\EvidenceSource;
use App\Video\Evidence\ProvenanceLevel;
use App\Video\Scene\ScenePurpose;
use App\Video\Scene\SemanticScene;
use App\Video\World\Entity;
use App\Video\World\EntityType;
use App\Video\World\Event;
use App\Video\World\Identity;
use App\Video\World\Relation;
use App\Video\World\VerifiedAttribute;
use App\Video\World\VerifiedWorldGraph;
use PHPUnit\Framework\TestCase;

/**
 * Editorial 5a: aesthetic là hàm THUẦN của ScenePurpose.
 *
 * Ranh giới đóng bằng type: aestheticFor() chỉ nhận ScenePurpose — không thấy
 * EntityType, subjects, Evidence. Nên taste KHÔNG THỂ dính chủ đề. "Establishing
 * shot thì calm/balanced" đúng cho Moonrise, Ferrari, sư tử, nhà máy.
 *
 * Đây là lần đầu taste được phép xuất hiện trong pipeline — và nó vẫn phải mù
 * chủ đề y như Intent, Timeline.
 */
class EditorialInterpreterTest extends TestCase
{
    private function aesthetic(ScenePurpose $p): SceneAesthetic
    {
        return (new EditorialInterpreter())->aestheticFor($p);
    }

    public function test_every_purpose_yields_a_complete_aesthetic(): void
    {
        // Editorial LUÔN điền — không scene nào thiếu taste. Đối lập với world{}
        // (fact) được phép vắng.
        foreach (ScenePurpose::cases() as $purpose) {
            $a = $this->aesthetic($purpose);

            $this->assertInstanceOf(Emotion::class, $a->emotion);
            $this->assertInstanceOf(Composition::class, $a->composition);
            $this->assertInstanceOf(LightIntensity::class, $a->lightIntensity);
            $this->assertInstanceOf(LightGrade::class, $a->lightGrade);
        }
    }

    public function test_is_deterministic(): void
    {
        $this->assertEquals(
            $this->aesthetic(ScenePurpose::Action),
            $this->aesthetic(ScenePurpose::Action),
        );
    }

    // ---- Chữ ký điện ảnh của từng purpose ----

    public function test_resolution_gets_the_golden_majestic_ending_look(): void
    {
        $a = $this->aesthetic(ScenePurpose::Resolution);

        $this->assertSame(Emotion::Majestic, $a->emotion);
        $this->assertSame(LightGrade::Golden, $a->lightGrade);
    }

    public function test_action_is_tense_and_harsh(): void
    {
        $a = $this->aesthetic(ScenePurpose::Action);

        $this->assertSame(Emotion::Tense, $a->emotion);
        $this->assertSame(LightIntensity::Harsh, $a->lightIntensity);
    }

    public function test_establish_is_calm(): void
    {
        $this->assertSame(Emotion::Calm, $this->aesthetic(ScenePurpose::Establish)->emotion);
    }

    public function test_comparison_is_symmetrical(): void
    {
        // So sánh hai chủ thể → bố cục cân đối để mắt đối chiếu.
        $this->assertSame(Composition::Symmetrical, $this->aesthetic(ScenePurpose::Comparison)->composition);
    }

    /**
     * Chốt bất biến: mọi purpose cho aesthetic khác biệt đủ để không nhàm, và
     * KHÔNG có purpose nào rơi vào default trống. Bảng policy phải phủ hết.
     */
    public function test_no_two_purposes_are_editorially_identical(): void
    {
        $seen = [];

        foreach (ScenePurpose::cases() as $purpose) {
            $a = $this->aesthetic($purpose);
            $key = "{$a->emotion->value}|{$a->composition->value}|{$a->lightIntensity->value}|{$a->lightGrade->value}";
            $seen[] = $key;
        }

        // Không đòi 7 khác nhau hoàn toàn (một số taste trùng là hợp lý), nhưng
        // phải có ít nhất vài chữ ký khác biệt — nếu tất cả giống nhau thì bảng
        // policy vô dụng.
        $this->assertGreaterThan(3, count(array_unique($seen)), 'bảng editorial quá đơn điệu — gần như mọi scene giống nhau');
    }

    // ---- prohibitionsFor(): §12, chỗ "no domes" thuộc về ----

    private function ev(): Evidence
    {
        return new Evidence('x', EvidenceSource::Body, 0, ProvenanceLevel::Direct);
    }

    private function entityWithAttribute(string $id, string $attribute, mixed $value): Entity
    {
        return new Entity($id, EntityType::Vehicle, [
            $attribute => [new VerifiedAttribute($attribute, $value, $this->ev(), ProvenanceLevel::Direct)],
        ], new Identity($id, false, $this->ev()));
    }

    public function test_prohibitions_empty_when_no_policy_configured(): void
    {
        $world = new VerifiedWorldGraph([$this->entityWithAttribute('moonrise', 'builder', 'Feadship')], [], []);

        $this->assertSame([], (new EditorialInterpreter())->prohibitionsFor($world));
    }

    public function test_prohibition_fires_when_entity_matches_policy(): void
    {
        // Đúng ví dụ minh hoạ ARCHITECTURE.md §12: integrated receivers → no domes.
        $policy = new EditorialPolicy(
            ['builder' => 'Feadship'],
            'domes',
            false,
            'integrated receivers instead of radomes',
        );
        $world = new VerifiedWorldGraph([$this->entityWithAttribute('moonrise', 'builder', 'Feadship')], [], []);

        $prohibitions = (new EditorialInterpreter([$policy]))->prohibitionsFor($world);

        $this->assertSame([[
            'entity_id' => 'moonrise',
            'attribute' => 'domes',
            'value'     => false,
            'reason'    => 'integrated receivers instead of radomes',
        ]], $prohibitions);
    }

    public function test_prohibition_does_not_fire_when_entity_does_not_match(): void
    {
        $policy = new EditorialPolicy(['builder' => 'Feadship'], 'domes', false, 'reason');
        $world  = new VerifiedWorldGraph([$this->entityWithAttribute('other', 'builder', 'Lurssen')], [], []);

        $this->assertSame([], (new EditorialInterpreter([$policy]))->prohibitionsFor($world));
    }

    public function test_prohibitions_never_mutate_world(): void
    {
        // §12 Rule #3: read-only. Type system đã bảo đảm — Entity/attributes
        // readonly nên không có cách nào viết code vi phạm; test này chỉ xác
        // nhận giá trị KHÔNG đổi sau khi gọi.
        $entity = $this->entityWithAttribute('moonrise', 'builder', 'Feadship');
        $world  = new VerifiedWorldGraph([$entity], [], []);
        $policy = new EditorialPolicy(['builder' => 'Feadship'], 'domes', false, 'reason');

        (new EditorialInterpreter([$policy]))->prohibitionsFor($world);

        $this->assertSame('Feadship', $entity->value('builder'));
    }

    // ---- candidatesFor()/microPhysicsFor(): Phase 3, ARCHITECTURE.md §18.4 ----

    private function physicalEntity(string $id, array $attributes = []): Entity
    {
        $attrs = [];
        foreach ($attributes as $name => $value) {
            $attrs[$name] = [new VerifiedAttribute($name, $value, $this->ev(), ProvenanceLevel::Direct)];
        }

        return new Entity($id, EntityType::PhysicalObject, $attrs);
    }

    private function scene(array $subjectIds, ScenePurpose $purpose = ScenePurpose::Action): SemanticScene
    {
        return new SemanticScene('s1', 'a1', 1, $purpose, $subjectIds);
    }

    public function test_hero_candidates_excludes_anchor_only_entities(): void
    {
        $crane = $this->physicalEntity('goliathcrane', ['equipment_class' => 'gantry']);
        $anchor = new Entity('unnamed_dock', EntityType::Landscape, []); // anchor-only, không attribute

        $world = new VerifiedWorldGraph([$crane, $anchor], [], []);
        $scene = $this->scene(['goliathcrane', 'unnamed_dock']);

        $candidates = (new EditorialInterpreter())->candidatesFor($scene, $world);

        $this->assertSame(['goliathcrane'], $candidates['hero_candidates']);
    }

    public function test_action_candidates_map_relation_type_to_action_type(): void
    {
        $crane = $this->physicalEntity('goliathcrane');
        $block = $this->physicalEntity('sternblock');
        $relation = new Relation('r1', 'goliathcrane', 'sternblock', 'lifts', $this->ev());

        $world = new VerifiedWorldGraph([$crane, $block], [$relation], []);
        $scene = $this->scene(['goliathcrane', 'sternblock']);

        $candidates = (new EditorialInterpreter())->candidatesFor($scene, $world);

        $this->assertCount(1, $candidates['action_candidates']);
        /** @var ActionCandidate $action */
        $action = $candidates['action_candidates'][0];
        $this->assertSame(ActionType::Lift, $action->type);
        $this->assertSame('goliathcrane', $action->actor);
        $this->assertSame('sternblock', $action->target);
    }

    public function test_action_candidates_skip_relation_with_no_matching_keyword(): void
    {
        // "built_by"/"original_owner" — quan hệ cấu trúc, KHÔNG phải hành động vật
        // lý. Rule 0: không ép mapping sai, bỏ qua thay vì đoán.
        $relation = new Relation('r1', 'moonrise', 'feadship', 'built_by', $this->ev());
        $world = new VerifiedWorldGraph([
            $this->physicalEntity('moonrise'), $this->physicalEntity('feadship'),
        ], [$relation], []);
        $scene = $this->scene(['moonrise', 'feadship']);

        $candidates = (new EditorialInterpreter())->candidatesFor($scene, $world);

        $this->assertSame([], $candidates['action_candidates']);
    }

    public function test_action_candidates_only_include_relations_touching_scene(): void
    {
        $relation = new Relation('r1', 'craneA', 'blockA', 'lifts', $this->ev());
        $world = new VerifiedWorldGraph([
            $this->physicalEntity('craneA'), $this->physicalEntity('blockA'), $this->physicalEntity('unrelated'),
        ], [$relation], []);
        // scene khác, không liên quan gì tới craneA/blockA
        $scene = $this->scene(['unrelated']);

        $candidates = (new EditorialInterpreter())->candidatesFor($scene, $world);

        $this->assertSame([], $candidates['action_candidates']);
    }

    public function test_modifiers_flag_heavy_object_above_weight_threshold(): void
    {
        $heavy = $this->physicalEntity('sternblock', ['weight_tons' => 1250]);
        $light = $this->physicalEntity('toolbox', ['weight_tons' => 12]);
        $relation1 = new Relation('r1', 'crane', 'sternblock', 'lifts', $this->ev());
        $relation2 = new Relation('r2', 'worker', 'toolbox', 'lifts', $this->ev());

        $world = new VerifiedWorldGraph(
            [$this->physicalEntity('crane'), $heavy, $this->physicalEntity('worker'), $light],
            [$relation1, $relation2], [],
        );
        $scene = $this->scene(['crane', 'sternblock', 'worker', 'toolbox']);

        $candidates = (new EditorialInterpreter())->candidatesFor($scene, $world);
        $byTarget = [];
        foreach ($candidates['action_candidates'] as $c) {
            $byTarget[$c->target] = $c;
        }

        $this->assertSame(['heavy_object'], $byTarget['sternblock']->modifiers);
        $this->assertSame([], $byTarget['toolbox']->modifiers);
    }

    public function test_micro_physics_for_heavy_object_returns_cable_tension(): void
    {
        $action = new ActionCandidate(ActionType::Lift, 'crane', 'sternblock', ['heavy_object']);

        $physics = (new EditorialInterpreter())->microPhysicsFor($action);

        $this->assertSame(['the lifting cable holds under visible tension'], $physics);
    }

    public function test_micro_physics_for_no_modifiers_returns_empty(): void
    {
        $action = new ActionCandidate(ActionType::Inspect, 'inspector', 'panel', []);

        $this->assertSame([], (new EditorialInterpreter())->microPhysicsFor($action));
    }

    // ---- ActionType::Position: bằng chứng thật qua nút 🎬, bài yacht/sự kiện,
    // 2026-07-22 (không phải công nghiệp — xác nhận vocab đã tổng quát đúng) ----

    public function test_docked_at_relation_maps_to_position_not_ignored(): void
    {
        // Đúng dữ liệu thật đã trích xuất: don_julio_yacht --docked_at--> pier_59.
        $relation = new Relation('r1', 'don_julio_yacht', 'pier_59', 'docked_at', $this->ev());
        $world = new VerifiedWorldGraph([
            $this->physicalEntity('don_julio_yacht'), $this->physicalEntity('pier_59'),
        ], [$relation], []);
        $scene = $this->scene(['don_julio_yacht', 'pier_59']);

        $candidates = (new EditorialInterpreter())->candidatesFor($scene, $world);

        $this->assertCount(1, $candidates['action_candidates']);
        $this->assertSame(ActionType::Position, $candidates['action_candidates'][0]->type);
    }

    public function test_inspired_by_relation_still_correctly_ignored(): void
    {
        // "inspired_by" là quan hệ ý tưởng, KHÔNG phải hành động vật lý camera
        // quay được — phải tiếp tục bị bỏ qua, không ép vào Position hay bất kỳ
        // type nào khác. Đúng dữ liệu thật: world_cup_bottle --inspired_by--> world_cup_final.
        $relation = new Relation('r2', 'world_cup_bottle', 'world_cup_final', 'inspired_by', $this->ev());
        $world = new VerifiedWorldGraph([
            $this->physicalEntity('world_cup_bottle'), $this->physicalEntity('world_cup_final'),
        ], [$relation], []);
        $scene = $this->scene(['world_cup_bottle', 'world_cup_final']);

        $candidates = (new EditorialInterpreter())->candidatesFor($scene, $world);

        $this->assertSame([], $candidates['action_candidates']);
    }

    /**
     * @dataProvider positionKeywordProvider
     */
    public function test_position_keywords_are_domain_agnostic(string $relationType): void
    {
        $relation = new Relation('r1', 'a', 'b', $relationType, $this->ev());
        $world = new VerifiedWorldGraph([$this->physicalEntity('a'), $this->physicalEntity('b')], [$relation], []);
        $scene = $this->scene(['a', 'b']);

        $candidates = (new EditorialInterpreter())->candidatesFor($scene, $world);

        $this->assertSame(ActionType::Position, $candidates['action_candidates'][0]->type);
    }

    public static function positionKeywordProvider(): array
    {
        // Đa domain thật: thuyền neo, xe đậu, máy bay hạ cánh — không riêng yacht.
        return [
            'boat moored'   => ['moored_at'],
            'car parked'    => ['parked_in'],
            'plane landed'  => ['landed_at'],
            'ship anchored' => ['anchored_near'],
        ];
    }

    // ---- world.events là nguồn candidate thứ 2 — bằng chứng thật, 2026-07-22 ----

    public function test_event_touching_scene_becomes_action_candidate_with_no_target(): void
    {
        // Đúng dữ liệu thật: event surprise_performance, entity=nas — KHÔNG có
        // entity thứ 2 (khác Relation).
        $event = new Event('e1', 'surprise_performance', 'nas', $this->ev());
        $world = new VerifiedWorldGraph([$this->physicalEntity('nas')], [], [$event]);
        $scene = $this->scene(['nas']);

        $candidates = (new EditorialInterpreter())->candidatesFor($scene, $world);

        $this->assertCount(1, $candidates['action_candidates']);
        $action = $candidates['action_candidates'][0];
        $this->assertSame(ActionType::Perform, $action->type);
        $this->assertSame('nas', $action->actor);
        $this->assertSame('', $action->target);
    }

    /**
     * @dataProvider triumphConfrontEvents
     */
    public function test_benchmark_evidence_events_now_map_to_triumph_or_confront(string $eventType, ActionType $expected): void
    {
        // Đúng type string thật thấy qua video:benchmark (10 bài Claude thật,
        // 2026-07-22): race_victory×5 (Superyacht Cup Palma), award_won×2
        // (Superyacht $370M), protest_clash + break_in (Beagles) — trước đó
        // KHÔNG khớp keyword nào, Director bị bỏ lỡ candidate hợp lệ.
        $event = new Event('e1', $eventType, 'subject', $this->ev());
        $world = new VerifiedWorldGraph([$this->physicalEntity('subject')], [], [$event]);
        $scene = $this->scene(['subject']);

        $candidates = (new EditorialInterpreter())->candidatesFor($scene, $world);

        $this->assertCount(1, $candidates['action_candidates']);
        $this->assertSame($expected, $candidates['action_candidates'][0]->type);
    }

    public static function triumphConfrontEvents(): array
    {
        return [
            'race_victory'   => ['race_victory', ActionType::Triumph],
            'award_won'      => ['award_won', ActionType::Triumph],
            'protest_clash'  => ['protest_clash', ActionType::Confront],
            'break_in'       => ['break_in', ActionType::Confront],
        ];
    }

    public function test_event_not_touching_scene_is_ignored(): void
    {
        $event = new Event('e1', 'surprise_performance', 'nas', $this->ev());
        $world = new VerifiedWorldGraph([$this->physicalEntity('nas'), $this->physicalEntity('other')], [], [$event]);
        $scene = $this->scene(['other']); // nas KHÔNG phải subject của scene này

        $candidates = (new EditorialInterpreter())->candidatesFor($scene, $world);

        $this->assertSame([], $candidates['action_candidates']);
    }

    public function test_event_with_no_matching_keyword_still_correctly_ignored(): void
    {
        $event = new Event('e1', 'sale', 'moonrise', $this->ev());
        $world = new VerifiedWorldGraph([$this->physicalEntity('moonrise')], [], [$event]);
        $scene = $this->scene(['moonrise']);

        $candidates = (new EditorialInterpreter())->candidatesFor($scene, $world);

        $this->assertSame([], $candidates['action_candidates']);
    }

    public function test_action_candidate_to_array_omits_empty_target(): void
    {
        $action = new ActionCandidate(ActionType::Perform, 'nas', '', []);

        $doc = $action->toArray();

        $this->assertArrayNotHasKey('target', $doc);
        $this->assertSame('nas', $doc['actor']);
    }

    public function test_action_candidate_to_array_keeps_non_empty_target(): void
    {
        $action = new ActionCandidate(ActionType::Lift, 'crane', 'sternblock', []);

        $doc = $action->toArray();

        $this->assertSame('sternblock', $doc['target']);
    }

    // ---- environmentFor(): Sprint 2, 2026-07-22 — CẤP VIDEO, chỉ khi đúng 1 Landscape ----

    private function landscapeEntity(string $id, array $attributes): Entity
    {
        $verified = [];
        foreach ($attributes as $name => $value) {
            $verified[$name] = [new VerifiedAttribute($name, $value, $this->ev(), ProvenanceLevel::Direct)];
        }

        return new Entity($id, EntityType::Landscape, $verified);
    }

    public function test_environment_empty_when_no_landscape_entity(): void
    {
        $world = new VerifiedWorldGraph([$this->entityWithAttribute('moonrise', 'hull_color', 'grey')], [], []);

        $this->assertSame([], (new EditorialInterpreter())->environmentFor($world));
    }

    public function test_environment_empty_when_two_or_more_landscape_entities(): void
    {
        // Không biết cảnh nào khớp landscape nào — KHÔNG đoán (Rule 0).
        $world = new VerifiedWorldGraph([
            $this->landscapeEntity('shipyard', ['weather' => 'clear skies']),
            $this->landscapeEntity('open_sea', ['weather' => 'light rain']),
        ], [], []);

        $this->assertSame([], (new EditorialInterpreter())->environmentFor($world));
    }

    public function test_environment_maps_free_text_to_closed_enum(): void
    {
        $world = new VerifiedWorldGraph([$this->landscapeEntity('shipyard', [
            'weather'      => 'a light drizzle over the yard',
            'time_of_day'  => 'just after sunset',
            'medium'       => 'the open sea',
            'light_source' => 'natural daylight',
            'location'     => 'the shipyard',
        ])], [], []);

        $this->assertSame([
            'weather'      => 'RAIN',
            'time_of_day'  => 'GOLDEN_HOUR',
            'medium'       => 'WATER',
            'light_source' => 'NATURAL',
            'location'     => 'the shipyard',
        ], (new EditorialInterpreter())->environmentFor($world));
    }

    public function test_environment_maps_river_to_water(): void
    {
        // Đúng bằng chứng thật qua video:benchmark: landscape "Hudson River"
        // (bài Tequila yacht), claim medium="river" bị bỏ sót trước đó —
        // environment_reason sai thành NO_MATCHING_ATTRIBUTES.
        $world = new VerifiedWorldGraph([
            $this->landscapeEntity('hudson_river', ['medium' => 'river']),
        ], [], []);

        $this->assertSame(
            ['medium' => 'WATER'],
            (new EditorialInterpreter())->environmentFor($world),
        );
    }

    public function test_environment_omits_attribute_that_matches_no_keyword(): void
    {
        // Truth có claim nhưng giá trị không khớp từ khoá nào — bỏ qua field
        // đó, KHÔNG ép về giá trị gần đúng nhất.
        $world = new VerifiedWorldGraph([$this->landscapeEntity('shipyard', [
            'weather' => 'unusual atmospheric conditions',
        ])], [], []);

        $this->assertSame([], (new EditorialInterpreter())->environmentFor($world));
    }

    public function test_environment_never_mutates_world(): void
    {
        $entity = $this->landscapeEntity('shipyard', ['weather' => 'clear skies']);
        $world  = new VerifiedWorldGraph([$entity], [], []);

        (new EditorialInterpreter())->environmentFor($world);

        $this->assertSame('clear skies', $entity->value('weather'));
    }

    // ---- environmentDiagnosisFor(): chỉ dùng cho benchmark, không phá contract environmentFor() ----

    public function test_diagnosis_no_landscape_entity(): void
    {
        $world = new VerifiedWorldGraph([$this->entityWithAttribute('moonrise', 'hull_color', 'grey')], [], []);

        $this->assertSame('NO_LANDSCAPE_ENTITY', (new EditorialInterpreter())->environmentDiagnosisFor($world));
    }

    public function test_diagnosis_multiple_landscapes(): void
    {
        $world = new VerifiedWorldGraph([
            $this->landscapeEntity('shipyard', ['weather' => 'clear skies']),
            $this->landscapeEntity('open_sea', ['weather' => 'light rain']),
        ], [], []);

        $this->assertSame('MULTIPLE_LANDSCAPES', (new EditorialInterpreter())->environmentDiagnosisFor($world));
    }

    public function test_diagnosis_no_matching_attributes(): void
    {
        $world = new VerifiedWorldGraph([
            $this->landscapeEntity('shipyard', ['weather' => 'unusual atmospheric conditions']),
        ], [], []);

        $this->assertSame('NO_MATCHING_ATTRIBUTES', (new EditorialInterpreter())->environmentDiagnosisFor($world));
    }

    public function test_diagnosis_success(): void
    {
        $world = new VerifiedWorldGraph([
            $this->landscapeEntity('shipyard', ['weather' => 'clear skies']),
        ], [], []);

        $this->assertSame('SUCCESS', (new EditorialInterpreter())->environmentDiagnosisFor($world));
    }
}
