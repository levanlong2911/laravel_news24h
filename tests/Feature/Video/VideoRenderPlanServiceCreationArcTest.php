<?php

namespace Tests\Feature\Video;

use App\Models\Article;
use App\Models\Category;
use App\Services\Admin\ClaudeWriterService;
use App\Services\VideoRenderPlanService;
use App\Video\Llm\ClaudeWriterAdapter;
use App\Video\Pipeline\VideoPipelineFactory;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Test tich hop cap Laravel (can config() that — config('video.creation_arc'))
 * cho VideoRenderPlanService::applyCreationArc() — ngoai le CO CHU DICH voi
 * Truth Layer: chen cac scene creation-arc BIA TRUOC scene that khi category
 * bai viet co mat trong config('video.creation_arc.categories').
 * Xem ARCHITECTURE.md §18.16.
 *
 * KHONG cham DB that: Article/Category dung trong bo nho + setRelation() thay
 * vi factory/migration — applyCreationArc() chi doc $article->category, khong
 * query gi khac. An toan chay tren DB that cua may dev, khong can RefreshDatabase.
 *
 * KHONG goi Claude: chi test applyCreationArc(), khong dung toi build().
 */
class VideoRenderPlanServiceCreationArcTest extends TestCase
{
    /** @return array<string, mixed> */
    private function applyCreationArc(array $renderPlan, Article $article): array
    {
        // applyCreationArc() KHONG goi LLM va KHONG dung pipeline — hai phu
        // thuoc duoi day chi de thoa chu ky constructor. Mock ClaudeWriterService
        // (class cu the, mock duoc) roi boc adapter that quanh no: khong cu goi
        // API nao xay ra. VideoPipelineFactory `new` that vi no khong co state.
        $service = new VideoRenderPlanService(
            new ClaudeWriterAdapter($this->createMock(ClaudeWriterService::class)),
            new VideoPipelineFactory,
        );

        $method = new ReflectionMethod(VideoRenderPlanService::class, 'applyCreationArc');
        $method->setAccessible(true);

        return $method->invoke($service, $renderPlan, $article);
    }

    private function articleWithCategory(?string $slug): Article
    {
        $article = new Article;
        $article->setRelation('category', $slug !== null ? new Category(['slug' => $slug]) : null);

        return $article;
    }

    /** @return array<string, mixed> */
    private function baseRenderPlan(): array
    {
        return [
            'story' => ['title' => 'x', 'language' => 'en', 'target_seconds' => 10],
            'world' => ['entities' => [
                ['id' => 'daybreak_yacht', 'type' => 'vehicle', 'attributes' => [], 'identity' => ['name' => 'Daybreak']],
            ]],
            'acts' => [
                ['id' => 'act_1', 'ordinal' => 1, 'source' => 'ENTITY', 'entity_ref' => 'daybreak_yacht'],
            ],
            'scenes' => [
                ['id' => 'scene_1', 'ordinal' => 1, 'act_id' => 'act_1'],
            ],
            'timeline' => [
                ['scene_id' => 'scene_1', 'start_sec' => 0.0, 'end_sec' => 5.0],
            ],
        ];
    }

    public function test_matching_category_replaces_real_scenes_with_the_arc(): void
    {
        $result = $this->applyCreationArc($this->baseRenderPlan(), $this->articleWithCategory('yacht'));

        // §18.22 (2026-07-30): arc THAY THẾ, không chèn thêm. Video của category
        // có phase_set CHỈ GỒM scene arc — 6 scene / 4 pha (Construction tách 2:
        // hạ khối + hạ động cơ; Experience tách 2: ngoại thất + trên boong).
        $this->assertCount(6, $result['scenes']);
        $this->assertSame([
            'creation_design',
            'creation_construction_hull',
            'creation_construction_engine',
            'creation_craftsmanship',
            'creation_experience_exterior',
            'creation_experience_onboard',
        ], array_column($result['scenes'], 'id'));

        // Scene thật KHÔNG được sót lại — nếu sót, ranh giới arc→thật quay về
        // cùng nguyên bài toán identity của §18.15.
        $this->assertNotContains('scene_1', array_column($result['scenes'], 'id'));
    }

    public function test_target_seconds_becomes_the_arc_length(): void
    {
        $result = $this->applyCreationArc($this->baseRenderPlan(), $this->articleWithCategory('yacht'));

        // 6 scene x 5s = 30. KHÔNG còn cộng dồn với 10s của plan thật.
        $this->assertSame(30, $result['story']['target_seconds']);
        $this->assertSame(0.0, $result['timeline'][0]['start_sec']);
        $this->assertCount(6, $result['timeline']);
        $this->assertSame(30.0, end($result['timeline'])['end_sec']);
    }

    public function test_superyacht_category_uses_the_same_vessel_phase_set(): void
    {
        $result = $this->applyCreationArc($this->baseRenderPlan(), $this->articleWithCategory('superyacht'));

        $this->assertSame('creation_design', $result['scenes'][0]['id']);
        $this->assertCount(6, $result['scenes']);
    }

    // ---- Thêm category mới = THÊM DỮ LIỆU, không sửa code (§1, §18.23) ----

    public function test_a_brand_new_category_gets_the_arc_with_zero_code_change(): void
    {
        // cars/moto CỐ TÌNH vắng khỏi config (chưa có tư liệu ảnh thật — §18.16).
        // Test này chứng minh: ngày thêm chúng vào, CHỈ cần thêm dữ liệu.
        //
        // Dùng slug bịa để khỏi phụ thuộc việc cars/moto bao giờ được thêm.
        config([
            'video.creation_arc.categories.motorbike' => 'two_wheeler',
            'video.creation_arc.phase_sets.two_wheeler' => [
                'identity' => ['final' => ['visual_identity' => 'a matte black naked bike']],
                'phases' => [
                    'design' => [
                        'purpose' => 'ESTABLISH',
                        'objective' => 'Show the frame taking shape on paper.',
                        'camera' => ['framing' => 'MEDIUM', 'movement' => 'PUSH_IN', 'speed' => 'SLOW'],
                        'aesthetic' => ['emotion' => 'CALM', 'composition' => 'CENTERED', 'light_intensity' => 'SOFT', 'light_grade' => 'NEUTRAL'],
                        'composition_note' => 'A technical drawing of {hero_name} on a workbench.',
                    ],
                ],
            ],
        ]);

        $result = $this->applyCreationArc($this->baseRenderPlan(), $this->articleWithCategory('motorbike'));

        $this->assertSame(['creation_design'], array_column($result['scenes'], 'id'));
        $this->assertSame('a matte black naked bike', $result['creative_identity']['final']['visual_identity']);
    }

    public function test_a_category_whose_phase_set_is_empty_keeps_the_real_scenes(): void
    {
        // ĐÂY là trạng thái của cars/moto NẾU ai đó khai category mà quên pha:
        // arc KHÔNG kích hoạt ⇒ scene thật là nội dung cuối ⇒ Producer/Director
        // VẪN PHẢI chạy. Bỏ creative ở đây sẽ ra scene không objective, không
        // director_notes — vừa hỏng output vừa KHÔNG tiết kiệm được gì, vì
        // creative đó có người dùng.
        config([
            'video.creation_arc.categories.motorbike' => 'two_wheeler',
            'video.creation_arc.phase_sets.two_wheeler' => ['phases' => []],
        ]);

        $renderPlan = $this->baseRenderPlan();
        $result = $this->applyCreationArc($renderPlan, $this->articleWithCategory('motorbike'));

        $this->assertSame($renderPlan, $result, 'phase_set rỗng ⇒ giữ nguyên plan, không thay gì');
        $this->assertSame(['scene_1'], array_column($result['scenes'], 'id'));
    }

    public function test_cars_and_moto_share_one_phase_set(): void
    {
        // ĐỔI 2026-07-31. Test này trước đây khoá cars/moto VẮNG MẶT, vì §18.16
        // nói viết template khi chưa có tư liệu là lặp lại sai lầm của v1. Lý do
        // đó đã hết: có tư liệu thật (một video AI 23 giây dựng lại Evo IX —
        // §18.26). Test đổi theo bằng chứng, không phải bị nới cho code chạy.
        //
        // Dùng CHUNG một phase_set (quyết định user): tháo lắp xe máy khác ô tô,
        // nhưng cấu trúc 7 pha thì chung — khác biệt để trong composition_note.
        $categories = config('video.creation_arc.categories', []);

        $this->assertSame('restoration', $categories['cars'] ?? null);
        $this->assertSame('restoration', $categories['moto'] ?? null);
        $this->assertSame($categories['cars'], $categories['moto'], 'hai category phải trỏ CÙNG một phase_set');
    }

    public function test_the_restoration_arc_never_moves_the_camera(): void
    {
        // ĐÂY LÀ BẤT BIẾN của phase_set này, không phải chi tiết trang trí.
        //
        // Bằng chứng (§18.26): trong video tham chiếu, nền giống HỆT nhau suốt
        // 23 giây — kệ lốp, màn hình, bàn nguội không nhích một milimet. Chính
        // khung cố định đó là thứ chứng minh cho người xem rằng đây vẫn là cùng
        // một chiếc xe, mạnh hơn mọi câu mô tả (so §18.15: chữ không đủ độ phân
        // giải để khoá silhouette).
        //
        // Đổi một pha sang PUSH_IN/TRACK là phá cơ chế đó. Nếu ai thật sự muốn
        // đổi, phải có bằng chứng render mới — và sửa test này cùng lúc.
        $phases = config('video.creation_arc.phase_sets.restoration.phases', []);

        $this->assertNotSame([], $phases);

        foreach ($phases as $name => $phase) {
            $this->assertSame('STATIC', $phase['camera']['movement'], "pha [$name] phải giữ máy đứng yên");
            // Đổi framing CŨNG là đổi vị trí máy — WIDE ở 6 pha rồi MEDIUM ở
            // pha thứ 7 thì nền không còn khớp nhau nữa.
            $this->assertSame('WIDE', $phase['camera']['framing'], "pha [$name] đổi framing là đổi vị trí máy");
        }
    }

    public function test_the_restoration_arc_opens_and_closes_on_the_same_empty_frame(): void
    {
        // Pha đầu: xe hỏng đứng MỘT MÌNH. Pha cuối: xe đi khỏi, phòng TRỐNG.
        // Cùng khung, khác nội dung — vòng tròn khép lại mà không cần lời nào.
        $phases = config('video.creation_arc.phase_sets.restoration.phases', []);
        $names = array_keys($phases);

        $this->assertSame('arrival', reset($names));
        $this->assertSame('drive_out', end($names));
        $this->assertStringContainsString('alone', $phases['arrival']['composition_note']);
        $this->assertStringContainsString('empty', $phases['drive_out']['micro_physics'][0]);
    }

    public function test_no_dangling_asset_reference_after_replacement(): void
    {
        // Scene arc trỏ tới `as_<hero>`, mà asset đó do Assembler sinh từ scene
        // thật — vừa bị thay đi. Đây là chỗ dễ để lọt dangling ref nhất.
        $result = $this->applyCreationArc($this->baseRenderPlan(), $this->articleWithCategory('yacht'));

        $assetIds = array_column($result['assets'] ?? [], 'id');

        foreach ($result['scenes'] as $scene) {
            foreach ($scene['asset_refs'] ?? [] as $ref) {
                $this->assertContains($ref, $assetIds, "asset_ref treo: {$ref} ở scene {$scene['id']}");
            }
        }
    }

    public function test_cars_and_moto_now_produce_the_restoration_arc(): void
    {
        // ĐỔI 2026-07-31. Test này trước đây khẳng định cars/moto KHÔNG có arc
        // (chưa có tư liệu — §18.16). Nay đã có tư liệu thật (§18.26) nên khẳng
        // định ngược lại. Ca "slug trong map nhưng phase_set rỗng" vẫn được phủ
        // bởi test_a_category_whose_phase_set_is_empty_keeps_the_real_scenes.
        foreach (['cars', 'moto'] as $slug) {
            $result = $this->applyCreationArc($this->baseRenderPlan(), $this->articleWithCategory($slug));

            $this->assertSame(
                ['creation_arrival', 'creation_teardown', 'creation_bare_shell', 'creation_bodywork',
                    'creation_paint', 'creation_reveal', 'creation_drive_out'],
                array_column($result['scenes'], 'id'),
                "[$slug] phải ra đủ 7 scene phục chế, đúng thứ tự",
            );
        }
    }

    public function test_non_matching_category_leaves_render_plan_unchanged(): void
    {
        $renderPlan = $this->baseRenderPlan();
        $result = $this->applyCreationArc($renderPlan, $this->articleWithCategory('politics'));

        $this->assertSame($renderPlan, $result);
    }

    public function test_null_category_leaves_render_plan_unchanged(): void
    {
        $renderPlan = $this->baseRenderPlan();
        $result = $this->applyCreationArc($renderPlan, $this->articleWithCategory(null));

        $this->assertSame($renderPlan, $result);
    }

    public function test_matching_category_without_vehicle_entity_leaves_render_plan_unchanged(): void
    {
        $renderPlan = $this->baseRenderPlan();
        $renderPlan['world']['entities'] = [
            ['id' => 'someone', 'type' => 'human', 'attributes' => []],
        ];

        $result = $this->applyCreationArc($renderPlan, $this->articleWithCategory('yacht'));

        $this->assertSame($renderPlan, $result);
    }

    // ---- scene.objective = scene intent (§18.18, 2026-07-30) ----
    //
    // Ở file Feature vì cần config() THẬT (đọc đúng config/video.php đang ship),
    // khác CreationArcPlannerTest chạy PHPUnit thuần với fixture — cùng lý do đã
    // tách VideoPipelineFactoryProductionPoliciesTest.

    public function test_every_shipped_phase_declares_its_own_objective(): void
    {
        // Một pha mới thêm mà quên objective sẽ làm test này đỏ ngay — đó là
        // toàn bộ mục đích của nó. Bug gốc là 6/15 scene thiếu objective.
        $phases = config('video.creation_arc.phase_sets.vessel.phases', []);

        $this->assertNotEmpty($phases, 'config phải có phase_set vessel thật');

        foreach ($phases as $key => $phase) {
            $this->assertArrayHasKey('objective', $phase, "pha '{$key}' thiếu objective");
            $this->assertNotSame('', trim($phase['objective']), "pha '{$key}' có objective rỗng");
        }
    }

    public function test_shipped_objectives_are_all_distinct(): void
    {
        // Mỗi pha mô tả một trạng thái vòng đời KHÁC nhau nên intent phải khác
        // nhau. Trùng nhau là dấu hiệu ai đó lại dùng một câu chung — đúng thứ
        // dữ liệu production đã bác (§18.18).
        $phases = config('video.creation_arc.phase_sets.vessel.phases', []);
        $objectives = array_column($phases, 'objective');

        $this->assertCount(count($objectives), array_unique($objectives), 'objective các pha phải khác nhau');
    }

    public function test_arc_scenes_in_the_real_config_all_carry_an_objective(): void
    {
        // Chạy qua đúng đường production (applyCreationArc + config thật), không
        // phải fixture — kiểm chính xác thứ RenderPlanQualityReport đã báo thiếu.
        $result = $this->applyCreationArc($this->baseRenderPlan(), $this->articleWithCategory('yacht'));

        $arcScenes = array_slice($result['scenes'], 0, 6);

        foreach ($arcScenes as $scene) {
            $this->assertArrayHasKey('objective', $scene, "scene {$scene['id']} vẫn thiếu objective");
        }
    }
}
