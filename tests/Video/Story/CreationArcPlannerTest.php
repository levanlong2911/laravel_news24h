<?php

namespace Tests\Video\Story;

use App\Video\Story\CreationArcPlanner;
use PHPUnit\Framework\TestCase;

/**
 * CreationArcPlanner sinh 3 scene BỊA CÓ CHỦ ĐÍCH (design/construction/
 * craftsmanship) neo bằng 1 heroId THẬT (id entity trong world.entities đã
 * qua Gatekeeper) — xem docs/video/ARCHITECTURE.md §18.9-18.14. Test này chỉ
 * xác nhận SHAPE đúng schema RenderPlan v1.0 và đúng cấu hình từ
 * config('video.creation_arc') — không test nội dung prompt cụ thể (đó là
 * data, không phải logic).
 */
class CreationArcPlannerTest extends TestCase
{
    private const HERO_ID = 'daybreak_yacht';

    private const HERO_NAME = 'Daybreak';

    /** Fixture khớp đúng shape config('video.creation_arc.phases') thật, không gọi config(). */
    private function phases(): array
    {
        return [
            'design' => [
                'purpose' => 'ESTABLISH',
                'camera' => ['framing' => 'WIDE', 'movement' => 'PUSH_IN', 'speed' => 'SLOW'],
                'aesthetic' => ['emotion' => 'CALM', 'composition' => 'CENTERED', 'light_intensity' => 'SOFT', 'light_grade' => 'NEUTRAL'],
                'composition_note' => 'A technical blueprint sketch of {hero_name}, side profile view.',
            ],
            'construction' => [
                'purpose' => 'PROCESS',
                'camera' => ['framing' => 'WIDE', 'movement' => 'STATIC', 'speed' => 'SLOW'],
                'aesthetic' => ['emotion' => 'DRAMATIC', 'composition' => 'RULE_OF_THIRDS', 'light_intensity' => 'HARSH', 'light_grade' => 'COOL'],
                'composition_note' => 'A partially-built {hero_name} structure in an industrial workshop.',
            ],
            'craftsmanship' => [
                'purpose' => 'DETAIL',
                'camera' => ['framing' => 'CLOSE', 'movement' => 'STATIC', 'speed' => 'SLOW'],
                'aesthetic' => ['emotion' => 'CALM', 'composition' => 'CENTERED', 'light_intensity' => 'SOFT', 'light_grade' => 'WARM'],
                'composition_note' => 'A craftsman hand-finishes {hero_name}.',
            ],
        ];
    }

    private function planner(): CreationArcPlanner
    {
        return new CreationArcPlanner($this->phases());
    }

    public function test_produces_exactly_3_phases_from_config(): void
    {
        $result = $this->planner()->plan(self::HERO_ID, self::HERO_NAME);

        $this->assertCount(3, $result['acts']);
        $this->assertCount(3, $result['scenes']);
        $this->assertCount(3, $result['timeline']);
    }

    public function test_scenes_reference_the_real_hero_entity_as_subject(): void
    {
        $result = $this->planner()->plan(self::HERO_ID, self::HERO_NAME);

        foreach ($result['scenes'] as $scene) {
            $this->assertSame([self::HERO_ID], $scene['subjects']);
            $this->assertSame(self::HERO_ID, $scene['camera']['target']);
        }
        foreach ($result['acts'] as $act) {
            $this->assertSame('ENTITY', $act['source']);
            $this->assertSame(self::HERO_ID, $act['entity_ref']);
        }
    }

    public function test_ordinals_are_contiguous_from_one(): void
    {
        $result = $this->planner()->plan(self::HERO_ID, self::HERO_NAME);

        $ordinals = array_column($result['scenes'], 'ordinal');
        $this->assertSame([1, 2, 3], $ordinals);
    }

    public function test_timeline_is_gapless_and_covers_seconds_per_scene(): void
    {
        $result = $this->planner()->plan(self::HERO_ID, self::HERO_NAME, secondsPerScene: 5.0);

        $this->assertSame(0.0, $result['timeline'][0]['start_sec']);
        foreach ($result['timeline'] as $i => $slot) {
            $this->assertEqualsWithDelta(5.0, $slot['end_sec'] - $slot['start_sec'], 1e-9);
            if ($i > 0) {
                $this->assertSame($result['timeline'][$i - 1]['end_sec'], $slot['start_sec']);
            }
        }
    }

    public function test_composition_note_substitutes_real_hero_name_not_id(): void
    {
        $result = $this->planner()->plan(self::HERO_ID, self::HERO_NAME);

        foreach ($result['scenes'] as $scene) {
            $this->assertStringContainsString('Daybreak', $scene['director_notes']['composition_note']);
            $this->assertStringNotContainsString('{hero_name}', $scene['director_notes']['composition_note']);
        }
    }

    public function test_falls_back_to_prettified_id_when_hero_name_is_null(): void
    {
        $result = $this->planner()->plan('red_convertible', null);

        foreach ($result['scenes'] as $scene) {
            $this->assertStringContainsString('red convertible', $scene['director_notes']['composition_note']);
        }
    }

    public function test_scene_shape_matches_renderplan_schema_fields(): void
    {
        $result = $this->planner()->plan(self::HERO_ID, self::HERO_NAME);

        foreach ($result['scenes'] as $scene) {
            $this->assertArrayHasKey('id', $scene);
            $this->assertArrayHasKey('ordinal', $scene);
            $this->assertArrayHasKey('act_id', $scene);
            $this->assertArrayHasKey('purpose', $scene);
            $this->assertArrayHasKey('subjects', $scene);
            $this->assertArrayHasKey('motion_intent', $scene);
            $this->assertArrayHasKey('camera', $scene);
            $this->assertArrayHasKey('aesthetic', $scene);
            $this->assertArrayHasKey('asset_refs', $scene);
            $this->assertArrayHasKey('director_notes', $scene);
            foreach (['framing', 'movement', 'speed', 'target'] as $key) {
                $this->assertArrayHasKey($key, $scene['camera']);
            }
            foreach (['emotion', 'composition', 'light_intensity', 'light_grade'] as $key) {
                $this->assertArrayHasKey($key, $scene['aesthetic']);
            }
        }
    }

    public function test_is_deterministic(): void
    {
        $a = $this->planner()->plan(self::HERO_ID, self::HERO_NAME);
        $b = $this->planner()->plan(self::HERO_ID, self::HERO_NAME);

        $this->assertSame(json_encode($a), json_encode($b));
    }

    /** Fixture giống shape thật RenderPlanAssembler::assemble() trả về (rút gọn, đủ field mergeInto() đụng tới). */
    private function realRenderPlan(): array
    {
        return [
            'story' => ['title' => 'x', 'language' => 'en', 'target_seconds' => 10],
            'acts' => [
                ['id' => 'act_1', 'ordinal' => 1, 'source' => 'ENTITY', 'entity_ref' => self::HERO_ID],
                ['id' => 'act_2', 'ordinal' => 2, 'source' => 'ENTITY', 'entity_ref' => self::HERO_ID],
            ],
            'scenes' => [
                ['id' => 'scene_1', 'ordinal' => 1, 'act_id' => 'act_1'],
                ['id' => 'scene_2', 'ordinal' => 2, 'act_id' => 'act_2'],
            ],
            'timeline' => [
                ['scene_id' => 'scene_1', 'start_sec' => 0.0, 'end_sec' => 5.0],
                ['scene_id' => 'scene_2', 'start_sec' => 5.0, 'end_sec' => 10.0],
            ],
        ];
    }

    // ---- §18.22 (2026-07-30): arc THAY THẾ scene thật, KHÔNG chèn thêm ----
    //
    // Đảo quyết định 2026-07-24. Bằng chứng từ compile thật: bài "The Sixth
    // Sense" ra 14 shot/$2.52, trong đó 8 scene thật confidence 0.1-0.3 và nói
    // về những con tàu KHÁC — ghép sau 6 scene đóng tàu là hai video dán vào
    // nhau, không phải một câu chuyện.

    public function test_merge_into_replaces_real_scenes_entirely(): void
    {
        $merged = $this->planner()->mergeInto($this->realRenderPlan(), self::HERO_ID, self::HERO_NAME);

        // 3 pha trong fixture ⇒ đúng 3 scene, KHÔNG còn scene thật nào.
        $this->assertCount(3, $merged['scenes']);
        $this->assertSame(
            ['creation_design', 'creation_construction', 'creation_craftsmanship'],
            array_column($merged['scenes'], 'id'),
        );
        $this->assertSame([1, 2, 3], array_column($merged['scenes'], 'ordinal'));
    }

    public function test_real_scenes_and_acts_do_not_survive(): void
    {
        // Bất biến quan trọng nhất của §18.22 — nếu scene thật sót lại thì
        // ranh giới arc→thật quay về, kèm nguyên bài toán §18.15.
        $merged = $this->planner()->mergeInto($this->realRenderPlan(), self::HERO_ID, self::HERO_NAME);

        $this->assertNotContains('scene_1', array_column($merged['scenes'], 'id'));
        $this->assertNotContains('scene_2', array_column($merged['scenes'], 'id'));
        $this->assertNotContains('act_1', array_column($merged['acts'], 'id'));
        $this->assertNotContains('act_2', array_column($merged['acts'], 'id'));
        $this->assertSame(['creation_design', 'creation_construction', 'creation_craftsmanship'],
            array_column($merged['timeline'], 'scene_id'));
    }

    public function test_target_seconds_is_the_arc_length_not_a_sum(): void
    {
        // Fixture thật có target_seconds = 10. Sau khi thay: 3 scene x 5s = 15,
        // KHÔNG phải 10 + 15 = 25 như hành vi cộng dồn cũ.
        $merged = $this->planner()->mergeInto($this->realRenderPlan(), self::HERO_ID, self::HERO_NAME, secondsPerScene: 5.0);

        $this->assertSame(15, $merged['story']['target_seconds']);
        $this->assertSame(0.0, $merged['timeline'][0]['start_sec'], 'timeline phải bắt đầu từ 0');
        $this->assertSame(15.0, end($merged['timeline'])['end_sec']);
    }

    public function test_video_level_data_survives_the_replacement(): void
    {
        // world/continuity/producer là dữ liệu cấp VIDEO, không thuộc scene nào
        // — thay scene KHÔNG được cuốn chúng đi. `world` đặc biệt quan trọng:
        // camera.target và entity_identity_facts() bên compiler đọc từ đó.
        $plan = $this->realRenderPlan();
        $plan['world'] = ['entities' => [['id' => self::HERO_ID, 'type' => 'vehicle', 'attributes' => []]]];
        $plan['continuity'] = ['invariants' => [['entity_id' => self::HERO_ID]], 'prohibitions' => []];
        $plan['producer'] = ['visual_promise' => 'giu nguyen'];

        $merged = $this->planner()->mergeInto($plan, self::HERO_ID, self::HERO_NAME);

        $this->assertSame($plan['world'], $merged['world']);
        $this->assertSame($plan['continuity'], $merged['continuity']);
        $this->assertSame($plan['producer'], $merged['producer']);
    }

    public function test_hero_asset_is_added_when_the_replaced_scenes_took_it_away(): void
    {
        // Scene arc tham chiếu `as_<hero>`, nhưng asset đó do Assembler sinh từ
        // scene THẬT — vừa bị thay đi. Thiếu nó là dangling ref.
        $plan = $this->realRenderPlan();
        $plan['assets'] = [];

        $merged = $this->planner()->mergeInto($plan, self::HERO_ID, self::HERO_NAME);

        $assetIds = array_column($merged['assets'], 'id');
        $this->assertContains('as_'.self::HERO_ID, $assetIds);

        // Và mọi asset_ref của scene phải phân giải được.
        foreach ($merged['scenes'] as $scene) {
            foreach ($scene['asset_refs'] ?? [] as $ref) {
                $this->assertContains($ref, $assetIds, "asset_ref treo: {$ref}");
            }
        }
    }

    public function test_an_existing_hero_asset_is_not_duplicated(): void
    {
        $plan = $this->realRenderPlan();
        $plan['assets'] = [['id' => 'as_'.self::HERO_ID, 'kind' => 'structure', 'entity_id' => self::HERO_ID, 'required' => true]];

        $merged = $this->planner()->mergeInto($plan, self::HERO_ID, self::HERO_NAME);

        $this->assertCount(1, array_filter($merged['assets'], fn ($a) => $a['id'] === 'as_'.self::HERO_ID));
    }

    public function test_merge_into_with_no_configured_phases_returns_plan_unchanged(): void
    {
        $planner = new CreationArcPlanner([]);
        $renderPlan = $this->realRenderPlan();

        $merged = $planner->mergeInto($renderPlan, self::HERO_ID, self::HERO_NAME);

        $this->assertSame($renderPlan, $merged);
    }

    // ---- creative_identity (§18.17): fact cấp VIDEO, sibling của world_environment ----

    public function test_merge_into_emits_creative_identity_at_video_level(): void
    {
        $identity = [
            'construction' => ['visual_identity' => 'bare grey steel hull, no paint'],
            'final' => ['visual_identity' => 'dark navy metallic hull, white superstructure'],
        ];
        $planner = new CreationArcPlanner($this->phases(), $identity);

        $merged = $planner->mergeInto($this->realRenderPlan(), self::HERO_ID, self::HERO_NAME);

        // Cấp GỐC, không nằm trong scene nào — vì nó áp cho cả scene thật phía
        // sau, đúng điểm §18.15 đã bỏ sót.
        $this->assertSame($identity, $merged['creative_identity']);
    }

    public function test_merge_into_omits_creative_identity_when_not_configured(): void
    {
        // Optional theo schema: vắng hẳn key, KHÔNG emit object rỗng.
        $merged = $this->planner()->mergeInto($this->realRenderPlan(), self::HERO_ID, self::HERO_NAME);

        $this->assertArrayNotHasKey('creative_identity', $merged);
    }

    public function test_creative_identity_is_passed_through_untouched(): void
    {
        // Planner KHÔNG nhào nặn identity — compiler Python mới quyết định
        // scene nào dùng trạng thái nào. Ở đây chỉ chuyển tiếp nguyên văn.
        $identity = ['final' => ['visual_identity' => 'exact string, must not be altered']];
        $planner = new CreationArcPlanner($this->phases(), $identity);

        $merged = $planner->mergeInto($this->realRenderPlan(), self::HERO_ID, self::HERO_NAME);

        $this->assertSame('exact string, must not be altered', $merged['creative_identity']['final']['visual_identity']);
    }

    // ---- scene.objective = SCENE INTENT, không phải video promise (§18.18, 2026-07-30) ----
    //
    // Lịch sử cần biết để không lặp lại: bug do RenderPlanQualityReport bắt được
    // trên plan production (6/15 scene thiếu objective, đúng 6 scene arc, vì arc
    // chèn SAU assembler). Bản sửa ĐẦU TIÊN là copy `producer.visual_promise`
    // xuống arc — và dữ liệu production bác ngay: promise thật nói "gleaming
    // 242-foot vessel MOVING THROUGH THE WATERS of midcoast Maine", trong khi
    // scene arc đang chiếu tờ bản vẽ và khối thép trần trong xưởng.
    //
    // Nên objective có HAI nguồn hợp lệ ở HAI CẤP: video promise (Producer) và
    // scene intent (planner sinh ra scene đó). Không cạnh tranh nhau.

    /** @return array<string, mixed> Phase template CÓ objective riêng cho từng pha. */
    private function phasesWithObjective(): array
    {
        $phases = $this->phases();
        $phases['design']['objective'] = 'Establish that this began as a drawing.';
        $phases['construction']['objective'] = 'Show it taking physical form.';
        $phases['craftsmanship']['objective'] = 'Show the hand work behind the finish.';

        return $phases;
    }

    public function test_each_phase_gets_its_ow_n_objective_from_its_template(): void
    {
        $arc = (new CreationArcPlanner($this->phasesWithObjective()))->plan(self::HERO_ID, self::HERO_NAME);

        $this->assertSame([
            'Establish that this began as a drawing.',
            'Show it taking physical form.',
            'Show the hand work behind the finish.',
        ], array_column($arc['scenes'], 'objective'));
    }

    public function test_arc_objectives_are_differen_t_from_each_other(): void
    {
        // Đây là bất biến quan trọng nhất của §18.18. Nếu ai "sửa" lại thành
        // copy một chuỗi dùng chung xuống mọi scene arc thì test này đỏ — và đó
        // chính là bug đã bị dữ liệu production bác bỏ.
        $arc = (new CreationArcPlanner($this->phasesWithObjective()))->plan(self::HERO_ID, self::HERO_NAME);

        $objectives = array_column($arc['scenes'], 'objective');

        $this->assertCount(3, array_unique($objectives), 'mỗi pha phải có intent riêng, không dùng chung một câu');
    }

    public function test_arc_objective_never_copies_the_producer_visual_promise(): void
    {
        // Plan thật CÓ producer với promise nói về thành phẩm đang chạy trên
        // biển. Scene arc TUYỆT ĐỐI không được nhận câu đó.
        $plan = $this->realRenderPlan();
        $plan['producer'] = [
            'target_audience' => 'x',
            'core_conflict' => 'y',
            'visual_promise' => 'Viewers will see the gleaming vessel moving through open water',
            'emotional_curve' => ['CALM'],
        ];

        $merged = (new CreationArcPlanner($this->phasesWithObjective()))
            ->mergeInto($plan, self::HERO_ID, self::HERO_NAME);

        foreach (array_slice($merged['scenes'], 0, 3) as $scene) {
            $this->assertNotSame(
                $plan['producer']['visual_promise'],
                $scene['objective'],
                "scene {$scene['id']} đang mô tả trạng thái vòng đời TRƯỚC thành phẩm — không được mang lời hứa về thành phẩm",
            );
        }
    }

    public function test_every_surviving_scene_carries_its_own_phase_objective(): void
    {
        // Sau §18.22 không còn scene thật nào sống sót, nên objective của
        // Producer cũng đi theo. MỌI scene còn lại phải mang intent của pha nó.
        $plan = $this->realRenderPlan();
        $plan['scenes'][0]['objective'] = 'real scene objective from producer';

        $merged = (new CreationArcPlanner($this->phasesWithObjective()))
            ->mergeInto($plan, self::HERO_ID, self::HERO_NAME);

        $objectives = array_column($merged['scenes'], 'objective');

        $this->assertCount(3, $objectives, 'cả 3 scene arc đều phải có objective');
        $this->assertNotContains('real scene objective from producer', $objectives);
        $this->assertCount(3, array_unique($objectives), 'mỗi pha một intent riêng');
    }

    public function test_objective_key_is_absent_when_phase_does_not_declare_one(): void
    {
        // Optional theo schema — vắng hẳn key, KHÔNG emit chuỗi rỗng.
        // `phases()` (fixture gốc) không khai objective nào.
        $arc = $this->planner()->plan(self::HERO_ID, self::HERO_NAME);

        foreach ($arc['scenes'] as $scene) {
            $this->assertArrayNotHasKey('objective', $scene);
        }
    }

    public function test_blank_objective_in_config_is_treated_as_absent(): void
    {
        // Nếu emit chuỗi trắng thì QualityReport sẽ báo "có objective" trong khi
        // tầng compile phía sau vẫn không nhận được gì — im lặng sai.
        $phases = $this->phases();
        $phases['design']['objective'] = '   ';

        $arc = (new CreationArcPlanner($phases))->plan(self::HERO_ID, self::HERO_NAME);

        $this->assertArrayNotHasKey('objective', $arc['scenes'][0]);
    }

    public function test_objective_is_set_by_plan_not_only_by_merge_into(): void
    {
        // Khác bản sửa đầu: objective là hàm của PHASE TEMPLATE, không phải của
        // plan được chèn vào — nên `plan()` gọi một mình cũng đã có nó, và
        // mergeInto() không cần đọc `producer` gì cả.
        $planner = new CreationArcPlanner($this->phasesWithObjective());

        $fromPlan = $planner->plan(self::HERO_ID, self::HERO_NAME)['scenes'][0]['objective'];
        $fromMerge = $planner->mergeInto($this->realRenderPlan(), self::HERO_ID, self::HERO_NAME)['scenes'][0]['objective'];

        $this->assertSame($fromPlan, $fromMerge);
    }

    public function test_world_is_passed_through_as_semantic_key(): void
    {
        // Laravel chỉ gửi KHOÁ; cách nói bằng tiếng Anh là việc của compiler Python.
        $phases = $this->phases();
        $phases['construction']['world'] = ['environment' => 'open_shipyard'];

        $scene = (new CreationArcPlanner($phases))->plan(self::HERO_ID, self::HERO_NAME)['scenes'][1];

        $this->assertSame(['environment' => 'open_shipyard'], $scene['world']);
    }

    public function test_scene_without_world_omits_the_key_entirely(): void
    {
        // Pha không diễn ra ở một cơ sở sản xuất (bàn vẽ, trên boong, ngoài biển)
        // thì KHÔNG có nơi chốn để khai. Emit `world: null` sẽ fail schema (đòi
        // object khi có mặt) và tệ hơn là mời compiler đi đoán.
        foreach ((new CreationArcPlanner($this->phases()))->plan(self::HERO_ID, self::HERO_NAME)['scenes'] as $scene) {
            $this->assertArrayNotHasKey('world', $scene);
        }
    }

}
