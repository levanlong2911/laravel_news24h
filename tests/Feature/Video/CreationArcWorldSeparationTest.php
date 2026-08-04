<?php

namespace Tests\Feature\Video;

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

    public function test_phase_prose_never_states_the_place_when_world_is_declared(): void
    {
        $checked = 0;

        foreach (config('video.creation_arc.phase_sets', []) as $setKey => $set) {
            foreach ($set['phases'] ?? [] as $phaseKey => $phase) {
                if (! isset($phase['world'])) {
                    continue;
                }
                $checked++;
                $note = strtolower($phase['composition_note'] ?? '');

                foreach (self::PLACE_WORDS as $word) {
                    $this->assertStringNotContainsString($word, $note, sprintf(
                        "%s.%s: composition_note nhắc nơi chốn ('%s') trong khi pha này đã khai world. ".
                        'Nơi chốn chỉ được sống ở world — nếu không, đổi nghiệp vụ phải sửa hai chỗ và chúng sẽ lệch nhau.',
                        $setKey, $phaseKey, $word,
                    ));
                }
            }
        }

        $this->assertGreaterThan(0, $checked, 'Không pha nào khai world — test này đang không bảo vệ gì cả.');
    }

    public function test_world_carries_semantic_keys_only_never_english(): void
    {
        // Laravel là Semantic Authority: nó nói "loại nơi nào", không nói "tả ra
        // sao". Giá trị phải là khoá snake_case khớp enum trong
        // contracts/renderplan/v1.0/schema.json, không phải một câu.
        foreach (config('video.creation_arc.phase_sets', []) as $setKey => $set) {
            foreach ($set['phases'] ?? [] as $phaseKey => $phase) {
                foreach ($phase['world'] ?? [] as $axis => $value) {
                    $this->assertMatchesRegularExpression(
                        '/^[a-z0-9]+(_[a-z0-9]+)*$/',
                        (string) $value,
                        "{$setKey}.{$phaseKey}.world.{$axis} = '{$value}' — phải là khoá ngữ nghĩa snake_case, không phải văn xuôi.",
                    );
                }
            }
        }
    }
}
