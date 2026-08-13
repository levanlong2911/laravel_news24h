<?php

namespace Tests\Video\Analysis;

use App\Video\Analysis\RenderPlanQualityReport;
use PHPUnit\Framework\TestCase;

/**
 * Báo cáo chất lượng RenderPlan cho người duyệt (2026-07-30).
 *
 * Mọi con số trong test "dữ liệu thật" ở cuối file đến từ RenderPlan THẬT đã
 * lưu (session `art_a25f63f3_260729_062733`, bài "$90M superyacht owned by
 * Carnival Cruise Line billionaire headed for midcoast") — không phải fixture
 * bịa. Nếu ai đổi ngưỡng hay logic, test đó cho biết ngay báo cáo còn khớp thực
 * tế đã quan sát hay không.
 */
class RenderPlanQualityReportTest extends TestCase
{
    /** @return array<string, mixed> Plan "sạch": không cảnh báo nào được bắn. */
    private function healthyPlan(): array
    {
        return [
            'story' => ['title' => 'Moonrise leaves the yard', 'language' => 'en', 'target_seconds' => 20],
            'world' => ['entities' => [
                [
                    'id' => 'moonrise',
                    'type' => 'vehicle',
                    'identity' => ['name' => 'Moonrise'],
                    'attributes' => ['hull_color' => 'dark navy', 'material' => 'steel', 'deck_finish' => 'teak'],
                ],
                [
                    'id' => 'harbor',
                    'type' => 'landscape',
                    'attributes' => ['weather' => 'clear'],
                ],
            ]],
            'world_environment' => ['weather' => 'CLEAR'],
            'acts' => [['id' => 'act_1']],
            'assets' => [['id' => 'as_moonrise']],
            'scenes' => [[
                'id' => 'scene_1',
                'act_id' => 'act_1',
                'subjects' => ['moonrise'],
                'asset_refs' => ['as_moonrise'],
                'camera' => ['target' => 'moonrise'],
                'objective' => 'show the vessel leaving',
            ]],
        ];
    }

    /** @return list<string> */
    private function codes(array $plan, int $threshold = 3): array
    {
        $report = (new RenderPlanQualityReport($threshold))->analyze($plan);

        return array_column($report['warnings'], 'code');
    }

    public function test_healthy_plan_produces_no_warnings(): void
    {
        // Nếu test này đỏ thì báo cáo đang bắn báo động giả — nghiêm trọng hơn
        // việc bỏ sót, vì nó dạy người duyệt bỏ qua cảnh báo.
        $this->assertSame([], $this->codes($this->healthyPlan()));
    }

    public function test_report_is_deterministic(): void
    {
        $report = new RenderPlanQualityReport;

        $this->assertSame($report->analyze($this->healthyPlan()), $report->analyze($this->healthyPlan()));
    }

    // ---- Hai scene nói cùng một điều ----
    //
    // Director chỉ được đưa 3 scene gần nhất nên không tự biết scene 5 có
    // trùng scene 1 không — chỉ báo cáo này nhìn thấy cả video.

    /** @return array<string, mixed> */
    private function planWithNewInformation(string ...$values): array
    {
        $plan = $this->healthyPlan();
        $scenes = [];

        foreach ($values as $i => $value) {
            $scene = $plan['scenes'][0];
            $scene['id'] = 'scene_'.($i + 1);
            $scene['director_notes'] = ['new_information' => $value];
            $scenes[] = $scene;
        }

        $plan['scenes'] = $scenes;

        return $plan;
    }

    public function test_scenes_saying_different_things_produce_no_warning(): void
    {
        $codes = $this->codes($this->planWithNewInformation(
            'the vessel leaves the shed',
            'the interior opens onto the pool deck',
        ));

        $this->assertNotContains('DUPLICATE_NEW_INFORMATION', $codes);
    }

    public function test_two_scenes_saying_the_same_thing_are_reported(): void
    {
        $plan = $this->planWithNewInformation(
            'the vessel leaves the shed',
            'the interior opens onto the pool deck',
            'the vessel leaves the shed',
        );

        $report = (new RenderPlanQualityReport)->analyze($plan);
        $warning = $this->warningWithCode($report, 'DUPLICATE_NEW_INFORMATION');

        $this->assertNotNull($warning);
        $this->assertSame(
            [['scene_id' => 'scene_3', 'duplicate_of' => 'scene_1']],
            $warning['detail']['duplicates'],
        );
    }

    public function test_duplicates_are_detected_beyond_the_three_scene_director_memory(): void
    {
        // scene_5 lặp scene_1 — Director chỉ thấy scene 2,3,4 nên không thể tự biết.
        $plan = $this->planWithNewInformation('a', 'b', 'c', 'd', 'a');

        $report = (new RenderPlanQualityReport)->analyze($plan);
        $warning = $this->warningWithCode($report, 'DUPLICATE_NEW_INFORMATION');

        $this->assertSame(
            [['scene_id' => 'scene_5', 'duplicate_of' => 'scene_1']],
            $warning['detail']['duplicates'],
        );
    }

    public function test_only_wording_differences_still_count_as_duplicate(): void
    {
        $codes = $this->codes($this->planWithNewInformation(
            'The vessel leaves the shed.',
            'the vessel leaves the shed',
        ));

        $this->assertContains('DUPLICATE_NEW_INFORMATION', $codes);
    }

    public function test_scenes_without_new_information_are_not_reported_as_duplicates(): void
    {
        // Creation Arc không qua Director nên không scene nào có field này —
        // báo mọi scene trùng nhau ở đó là báo động giả.
        $codes = $this->codes($this->healthyPlan());

        $this->assertNotContains('DUPLICATE_NEW_INFORMATION', $codes);
    }

    public function test_empty_new_information_is_ignored(): void
    {
        $codes = $this->codes($this->planWithNewInformation('', '   ', ''));

        $this->assertNotContains('DUPLICATE_NEW_INFORMATION', $codes);
    }

    /** @return array<string, mixed>|null */
    private function warningWithCode(array $report, string $code): ?array
    {
        foreach ($report['warnings'] as $warning) {
            if ($warning['code'] === $code) {
                return $warning;
            }
        }

        return null;
    }

    // ---- 1. Hero determinability ----

    public function test_hero_not_determinable_when_no_entity_name_appears_in_title(): void
    {
        $plan = $this->healthyPlan();
        $plan['story']['title'] = 'Billionaire heads for the midcoast';

        $codes = $this->codes($plan);

        $this->assertContains('HERO_NOT_DETERMINABLE', $codes);
    }

    public function test_hero_determinable_when_title_names_an_entity(): void
    {
        // healthyPlan(): tiêu đề "Moonrise leaves the yard" có tên entity.
        $this->assertNotContains('HERO_NOT_DETERMINABLE', $this->codes($this->healthyPlan()));
    }

    public function test_hero_name_must_match_on_word_boundary_not_substring(): void
    {
        // Cùng loại lỗi đã sửa ở EditorialInterpreter: `str_contains()` sẽ thấy
        // "Nix" trong "Nixon" và tưởng bài nói về con tàu.
        $plan = $this->healthyPlan();
        $plan['story']['title'] = 'Nixon signs the shipping bill';
        $plan['world']['entities'][0]['identity']['name'] = 'Nix';

        $this->assertContains('HERO_NOT_DETERMINABLE', $this->codes($plan));
    }

    public function test_entities_without_a_name_are_skipped_not_counted_as_match(): void
    {
        // Entity vô danh là HỢP LỆ (Gatekeeper::verifyIdentity trả null khi tên
        // không truy được về bài) — không được coi là khớp tiêu đề.
        $plan = $this->healthyPlan();
        unset($plan['world']['entities'][0]['identity']);

        $report = (new RenderPlanQualityReport)->analyze($plan);

        $this->assertSame([], $report['metrics']['entities_named_in_title']);
        $this->assertContains('HERO_NOT_DETERMINABLE', array_column($report['warnings'], 'code'));
    }

    // ---- 2. Độ giàu chi tiết mô tả ----

    public function test_descriptive_richness_counts_distinct_names_not_repeated_values(): void
    {
        // Cùng một `vessel_type` lặp ở 3 entity KHÔNG giàu hơn 1 entity — đây là
        // đúng hình dạng dữ liệu thật đã quan sát.
        $plan = $this->healthyPlan();
        $plan['world']['entities'] = [
            ['id' => 'a', 'type' => 'vehicle', 'identity' => ['name' => 'Moonrise'], 'attributes' => ['vessel_type' => 'motor']],
            ['id' => 'b', 'type' => 'vehicle', 'attributes' => ['vessel_type' => 'sail']],
            ['id' => 'c', 'type' => 'vehicle', 'attributes' => ['vessel_type' => 'motor']],
        ];

        $report = (new RenderPlanQualityReport)->analyze($plan);

        $this->assertSame(['vessel_type'], $report['metrics']['descriptive_attribute_names']);
        $this->assertSame(3, $report['metrics']['attribute_values_total']);
        $this->assertContains('LOW_DESCRIPTIVE_RICHNESS', array_column($report['warnings'], 'code'));
    }

    public function test_numeric_attributes_do_not_count_as_descriptive(): void
    {
        // Lọc theo KIỂU, không theo tên (§1: không nhồi kiến thức ngành vào code).
        $plan = $this->healthyPlan();
        $plan['world']['entities'] = [[
            'id' => 'moonrise',
            'type' => 'vehicle',
            'identity' => ['name' => 'Moonrise'],
            'attributes' => ['length_feet' => 242, 'year_built' => 1992, 'max_speed_knots' => 20, 'guest_capacity' => 10],
        ]];

        $report = (new RenderPlanQualityReport)->analyze($plan);

        $this->assertSame([], $report['metrics']['descriptive_attribute_names']);
        $this->assertSame(4, $report['metrics']['attribute_values_total'], 'vẫn phải đếm được là có 4 giá trị');
        $this->assertContains('LOW_DESCRIPTIVE_RICHNESS', array_column($report['warnings'], 'code'));
    }

    public function test_multi_value_attribute_is_counted_once_by_name(): void
    {
        // Một tên MANG NHIỀU giá trị là chuyện thường (beach club VÀ spa VÀ
        // helipad) — Gatekeeper cố ý cho phép. Tên vẫn tính một lần.
        $plan = $this->healthyPlan();
        $plan['world']['entities'] = [[
            'id' => 'moonrise',
            'type' => 'vehicle',
            'identity' => ['name' => 'Moonrise'],
            'attributes' => ['amenity' => ['beach club', 'spa', 'helipad']],
        ]];

        $report = (new RenderPlanQualityReport)->analyze($plan);

        $this->assertSame(['amenity'], $report['metrics']['descriptive_attribute_names']);
        $this->assertSame(3, $report['metrics']['attribute_values_total']);
    }

    public function test_threshold_is_injectable(): void
    {
        // Ngưỡng default (3) là PHỎNG ĐOÁN từ N=1 — phải đổi được mà không sửa code.
        $plan = $this->healthyPlan();
        $plan['world']['entities'][0]['attributes'] = ['hull_color' => 'navy'];

        $this->assertContains('LOW_DESCRIPTIVE_RICHNESS', $this->codes($plan, threshold: 3));
        $this->assertNotContains('LOW_DESCRIPTIVE_RICHNESS', $this->codes($plan, threshold: 1));
    }

    // ---- 3. Môi trường ----

    public function test_no_environment_warning_when_world_environment_key_is_absent(): void
    {
        $plan = $this->healthyPlan();
        unset($plan['world_environment']);

        $this->assertContains('NO_ENVIRONMENT', $this->codes($plan));
    }

    public function test_no_environment_message_explains_the_multiple_landscape_case(): void
    {
        // Hai landscape → environmentFor() trả rỗng CÓ CHỦ ĐÍCH (không đoán cảnh
        // nào ứng với cái nào). Người duyệt cần biết đó là lý do, không phải
        // "bài không nói gì về môi trường".
        $plan = $this->healthyPlan();
        unset($plan['world_environment']);
        $plan['world']['entities'][] = ['id' => 'open_sea', 'type' => 'landscape', 'attributes' => []];

        $report = (new RenderPlanQualityReport)->analyze($plan);
        $warning = current(array_filter($report['warnings'], fn ($w) => $w['code'] === 'NO_ENVIRONMENT'));

        $this->assertStringContainsString('Nhiều hơn một entity bối cảnh', $warning['message']);
        $this->assertSame(['harbor', 'open_sea'], $warning['detail']['landscape_entity_ids']);
    }

    // ---- 4. Tham chiếu treo ----

    public function test_dangling_subject_is_reported(): void
    {
        $plan = $this->healthyPlan();
        $plan['scenes'][0]['subjects'] = ['moonrise_yacht']; // id lệch với entity 'moonrise'

        $report = (new RenderPlanQualityReport)->analyze($plan);
        $warning = current(array_filter($report['warnings'], fn ($w) => $w['code'] === 'DANGLING_REFERENCE'));

        $this->assertSame([[
            'scene_id' => 'scene_1', 'field' => 'subjects', 'missing_id' => 'moonrise_yacht',
        ]], $warning['detail']['references']);
    }

    public function test_dangling_act_id_and_asset_ref_are_reported(): void
    {
        $plan = $this->healthyPlan();
        $plan['scenes'][0]['act_id'] = 'act_missing';
        $plan['scenes'][0]['asset_refs'] = ['as_missing'];

        $report = (new RenderPlanQualityReport)->analyze($plan);
        $warning = current(array_filter($report['warnings'], fn ($w) => $w['code'] === 'DANGLING_REFERENCE'));

        $this->assertSame(
            ['act_id', 'asset_refs'],
            array_column($warning['detail']['references'], 'field'),
        );
    }

    public function test_non_entity_camera_target_is_observed_but_never_warned(): void
    {
        // QUYẾT ĐỊNH CÓ CHỦ ĐÍCH, đừng "sửa" thành cảnh báo: `camera.target`
        // ĐƯỢC PHÉP trỏ tới vật thể bịa (schema định nghĩa nó là `slug`, không
        // ràng buộc entity). Bằng chứng thật: 4 scene Creation Arc trỏ tới
        // design_drawing/marine_engine/hull_seam/upper_deck — cố ý. Cảnh báo ở
        // đây = 4 báo động giả mỗi video.
        $plan = $this->healthyPlan();
        $plan['scenes'][0]['camera']['target'] = 'design_drawing';

        $report = (new RenderPlanQualityReport)->analyze($plan);

        $this->assertSame([], array_column($report['warnings'], 'code'), 'không được bắn cảnh báo nào');
        $this->assertSame(
            [['scene_id' => 'scene_1', 'target' => 'design_drawing']],
            $report['metrics']['non_entity_camera_targets'],
            'nhưng vẫn phải ghi lại để người duyệt soi được lỗi chính tả',
        );
    }

    // ---- 5. Scene thiếu objective ----

    public function test_scenes_missing_objective_are_listed(): void
    {
        $plan = $this->healthyPlan();
        $plan['scenes'][] = ['id' => 'creation_design', 'act_id' => 'act_1', 'subjects' => ['moonrise']];
        $plan['scenes'][] = ['id' => 'creation_craftsmanship', 'act_id' => 'act_1', 'subjects' => ['moonrise'], 'objective' => '  '];

        $report = (new RenderPlanQualityReport)->analyze($plan);
        $warning = current(array_filter($report['warnings'], fn ($w) => $w['code'] === 'SCENES_MISSING_OBJECTIVE'));

        $this->assertSame(['creation_design', 'creation_craftsmanship'], $warning['detail']['scene_ids']);
        $this->assertStringContainsString('2/3 scene', $warning['message']);
    }

    // ---- Dữ liệu THẬT: khoá lại đúng những gì đã quan sát trên plan production ----

    public function test_real_production_plan_shape_fires_exactly_the_observed_warnings(): void
    {
        // Rút gọn từ RenderPlan thật (7 entity, 15 scene). Giữ nguyên: tiêu đề
        // không chứa tên tàu nào; chỉ `vessel_type` là attribute chuỗi; 2
        // landscape nên không có world_environment; scene Creation Arc trỏ camera
        // tới vật thể bịa và không có objective.
        $plan = [
            'story' => ['title' => '$90M superyacht owned by Carnival Cruise Line billionaire headed for midcoast'],
            'world' => ['entities' => [
                ['id' => 'sixth_sense', 'type' => 'vehicle', 'identity' => ['name' => 'The Sixth Sense'],
                    'attributes' => ['length_feet' => 242, 'vessel_type' => 'motor']],
                ['id' => 'mylin_iv', 'type' => 'vehicle', 'identity' => ['name' => 'Mylin IV'],
                    'attributes' => ['length_meters' => 61, 'year_built' => 1992]],
                ['id' => 'micky_arison', 'type' => 'human', 'identity' => ['name' => 'Micky Arison'], 'attributes' => []],
                ['id' => 'bar_harbor', 'type' => 'landscape', 'identity' => ['name' => 'Bar Harbor'], 'attributes' => []],
                ['id' => 'boothbay_harbor', 'type' => 'landscape', 'identity' => ['name' => 'Boothbay Harbor'], 'attributes' => []],
            ]],
            'acts' => [['id' => 'creation_design_act'], ['id' => 'act_1']],
            'assets' => [['id' => 'as_sixth_sense']],
            'scenes' => [
                ['id' => 'creation_design', 'act_id' => 'creation_design_act', 'subjects' => ['sixth_sense'],
                    'asset_refs' => ['as_sixth_sense'], 'camera' => ['target' => 'design_drawing']],
                ['id' => 'scene_1', 'act_id' => 'act_1', 'subjects' => ['mylin_iv'],
                    'camera' => ['target' => 'mylin_iv'], 'objective' => 'reveal the vessel'],
            ],
        ];

        $report = (new RenderPlanQualityReport)->analyze($plan);

        // Ba cảnh báo, KHÔNG có DANGLING_REFERENCE (mọi subject/act/asset đều
        // phân giải được — camera.target bịa cố ý thì không tính).
        $this->assertSame([
            'HERO_NOT_DETERMINABLE',
            'LOW_DESCRIPTIVE_RICHNESS',
            'NO_ENVIRONMENT',
            'SCENES_MISSING_OBJECTIVE',
        ], array_column($report['warnings'], 'code'));

        $this->assertSame([], $report['metrics']['entities_named_in_title']);
        $this->assertSame(['vessel_type'], $report['metrics']['descriptive_attribute_names']);
        $this->assertSame(['bar_harbor', 'boothbay_harbor'], $report['metrics']['landscape_entity_ids']);
        $this->assertSame(['creation_design'], $report['metrics']['scenes_missing_objective']);
        $this->assertSame(
            [['scene_id' => 'creation_design', 'target' => 'design_drawing']],
            $report['metrics']['non_entity_camera_targets'],
        );
    }
}
