<?php

namespace Tests\Feature\Video;

use App\Video\Story\CreationArcPlanner;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Tests\TestCase;

/**
 * NƠI CHỐN chỉ được sống ở MỘT chỗ: `phase.world` (khoá ngữ nghĩa).
 *
 * Bối cảnh: trước đây mô tả thế giới nằm lẫn trong `composition_note` của từng
 * pha VÀ trong hồ sơ ngành bên Python (`anchor_setting`) — cùng một sự thật
 * viết ở 4 chỗ. Hậu quả đo được (2026-08-03): prose ghi "At an open waterside
 * shipyard under a clear sky" trong khi nghiệp vụ nói du thuyền đóng trong nhà
 * xưởng, và không có gì phát hiện được mâu thuẫn đó cho tới lúc nhìn ảnh render.
 *
 * Hai test dưới đây khoá lại ranh giới:
 *   - Laravel chỉ gửi KHOÁ, không gửi tiếng Anh  → Python tự diễn đạt
 *   - prose không được nhắc nơi chốn nữa         → không còn hai nguồn để chọi
 *
 * Đây là test CONFIG (dữ liệu người viết), nên nó phải boot Laravel để đọc
 * config('video') thật — khác CreationArcPlannerTest vốn cố ý chạy trên fixture
 * và không gọi config().
 */
class CreationArcWorldSeparationTest extends TestCase
{
    /**
     * Từ chỉ NƠI CHỐN. Không phải danh sách "từ cấm" mở rộng vô hạn — chỉ đủ bắt
     * đúng loại lỗi đã xảy ra thật. Muốn tả nơi chốn thì khai `world`, đừng viết
     * vào prose.
     */
    private const PLACE_WORDS = ['shipyard', 'hall', 'sky', 'outdoor', 'indoor', 'dry dock', 'quay', 'workshop'];

    public function test_phase_prose_never_states_the_place_when_setting_is_declared(): void
    {
        $checked = 0;

        foreach (config('video.creation_arc.phase_sets', []) as $setKey => $set) {
            foreach ($set['phases'] ?? [] as $phaseKey => $phase) {
                if (! isset($phase['setting'])) {
                    continue;
                }
                $checked++;
                $note = strtolower($phase['composition_note'] ?? '');

                foreach (self::PLACE_WORDS as $word) {
                    $this->assertStringNotContainsString($word, $note, sprintf(
                        "%s.%s: composition_note nhắc nơi chốn ('%s') trong khi pha này đã khai setting. ".
                        'Nơi chốn chỉ được sống ở setting — nếu không, đổi nghiệp vụ phải sửa hai chỗ và chúng sẽ lệch nhau.',
                        $setKey, $phaseKey, $word,
                    ));
                }
            }
        }

        $this->assertGreaterThan(0, $checked, 'Không pha nào khai world — test này đang không bảo vệ gì cả.');
    }

    /**
     * MẮT NỐI CUỐI: plan ĐÃ MERGE Creation Arc phải qua được schema v1.0.
     *
     * `RenderPlanSchemaTest` chỉ validate golden fixture — fixture đó KHÔNG có
     * scene do Creation Arc sinh, nên `scene.world` và `director_notes.crowd`
     * (hai field vừa thêm) chưa từng bị schema soi. Và production KHÔNG validate
     * (schema chỉ được đọc trong test), nên một field phá contract sẽ đi thẳng
     * sang Python mà không ai chặn.
     *
     * Test này chạy ĐÚNG đường thật: config/video.php -> CreationArcPlanner ->
     * mergeInto -> schema.
     */
    public function test_creation_arc_plan_satisfies_the_frozen_contract(): void
    {
        $fixture = __DIR__ . '/../../../contracts/renderplan/v1.0/fixtures/moonrise.json';
        $schemaPath = __DIR__ . '/../../../contracts/renderplan/v1.0/schema.json';
        $this->assertFileExists($fixture, 'Thiếu golden fixture — không có plan hợp lệ để merge arc vào.');

        $plan = json_decode(file_get_contents($fixture), true, 512, JSON_THROW_ON_ERROR);
        $heroId = $plan['world']['entities'][0]['id'];

        $set = config('video.creation_arc.phase_sets.vessel');
        $merged = (new CreationArcPlanner($set['phases'], $set['identity'] ?? []))
            ->mergeInto($plan, $heroId, 'Test Vessel');

        // Có ít nhất một scene mang `world` và một scene mang `crowd` — nếu không,
        // test này đang xanh mà chẳng bảo vệ gì.
        $withWorld = array_filter($merged['scenes'], fn ($s) => isset($s['setting']));
        $withCrowd = array_filter($merged['scenes'], fn ($s) => ! empty($s['director_notes']['crowd']));
        $this->assertNotEmpty($withWorld, 'Không scene nào có setting — test không bảo vệ gì.');
        $this->assertNotEmpty($withCrowd, 'Không scene nào có crowd — test không bảo vệ gì.');

        $result = (new Validator())->validate(
            json_decode(json_encode($merged), false, 512, JSON_THROW_ON_ERROR),
            json_decode(file_get_contents($schemaPath), false, 512, JSON_THROW_ON_ERROR),
        );

        if ($result->hasError()) {
            $this->fail("Plan có Creation Arc KHÔNG qua schema v1.0:\n".json_encode(
                (new ErrorFormatter())->format($result->error()),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));
        }
        $this->addToAssertionCount(1);
    }

    public function test_setting_carries_semantic_keys_only_never_english(): void
    {
        // Laravel là Semantic Authority: nó nói "loại nơi nào", không nói "tả ra
        // sao". Giá trị phải là khoá snake_case khớp enum trong
        // contracts/renderplan/v1.0/schema.json, không phải một câu.
        foreach (config('video.creation_arc.phase_sets', []) as $setKey => $set) {
            foreach ($set['phases'] ?? [] as $phaseKey => $phase) {
                foreach ($phase['setting'] ?? [] as $axis => $value) {
                    $this->assertMatchesRegularExpression(
                        '/^[a-z0-9]+(_[a-z0-9]+)*$/',
                        (string) $value,
                        "{$setKey}.{$phaseKey}.setting.{$axis} = '{$value}' — phải là khoá ngữ nghĩa snake_case, không phải văn xuôi.",
                    );
                }
            }
        }
    }
}
