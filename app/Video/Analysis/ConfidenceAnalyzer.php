<?php

namespace App\Video\Analysis;

/**
 * "RenderPlan này có bao nhiêu trong 8 layer mà Python MotionComposer.compose()
 * dùng để tính confidence?" — đọc THẲNG từ RenderPlan đã assemble (mảng), KHÔNG
 * gọi sang Python. Đây là PROJECTION, không phải nơi ĐỊNH NGHĨA công thức —
 * công thức thật sống ở `media_runtime/director/motion.py` (Python repo);
 * class này chỉ soi xem RenderPlan có đủ nguyên liệu cho từng layer hay không.
 *
 * Vì sao tách khỏi VideoBenchmark: command không được biết công thức 8-layer
 * là gì (coverage hôm nay, quality score mai sau) — chỉ gọi analyze() và ghi
 * lại. Đổi công thức chỉ sửa class này, không đụng command.
 *
 * "Có mặt" nghĩa là "tồn tại ở ÍT NHẤT 1 scene" (trừ `environment`, cấp video).
 * Đây là proxy THÔ cho "pipeline có sinh được loại dữ liệu này cho bài báo
 * không" — không phải "shot nào cũng có", việc đó là của Python per-shot.
 */
final class ConfidenceAnalyzer
{
    /**
     * Bump khi ĐỔI công thức (thêm/bớt layer, coverage->quality...) — không
     * phải khi pipeline_version đổi. Benchmark ghi cột riêng để so sánh 2 lần
     * chạy có cùng công thức không.
     */
    public const VERSION = 'coverage-v1';

    /** Thứ tự khớp đúng `layers = [...]` trong motion.py — xem [[project_motion_prompt_formula]].
     * ĐÂY là nguồn sự thật DUY NHẤT cho tổng số layer — analyze() tự đếm
     * count(self::LAYERS), không hardcode số 8 ở đâu khác. Đổi công thức chỉ
     * cần sửa mảng này (+ bump VERSION), không phải tìm số "8" rải rác. */
    private const LAYERS = [
        'objective', 'primary', 'secondary', 'environment',
        'micro_physics', 'camera_path', 'visual_style', 'negative',
    ];

    /**
     * Layer có code path THẬT, có thể ra data thật hôm nay — dùng để tính
     * `implementedCoverageScore`, tách khỏi layer cố tình chưa xây
     * (`visual_style` — chờ Producer/StylePlanner, chưa có bằng chứng cần).
     * `negative` nằm trong đây kể từ khi có EditorialPolicy thật trong
     * config/video.php (2026-07-22, VideoPipelineFactory) — trước đó dù có
     * code cũng luôn rỗng vì policy mặc định trống.
     */
    private const IMPLEMENTED_LAYERS = [
        'objective', 'primary', 'secondary', 'environment',
        'micro_physics', 'camera_path', 'negative',
    ];

    public function totalLayers(): int
    {
        return count(self::LAYERS);
    }

    /**
     * @param array<string, mixed> $renderPlan
     */
    public function analyze(array $renderPlan): ConfidenceReport
    {
        $present = [];

        foreach (self::LAYERS as $layer) {
            if ($this->layerHasData($layer, $renderPlan)) {
                $present[] = $layer;
            }
        }

        $missing = array_values(array_diff(self::LAYERS, $present));

        $implementedPresent = array_intersect($present, self::IMPLEMENTED_LAYERS);

        return new ConfidenceReport(
            count(self::LAYERS) > 0 ? count($present) / count(self::LAYERS) : 0.0,
            $present,
            $missing,
            count(self::IMPLEMENTED_LAYERS) > 0 ? count($implementedPresent) / count(self::IMPLEMENTED_LAYERS) : 0.0,
        );
    }

    /**
     * @param array<string, mixed> $renderPlan
     */
    private function layerHasData(string $layer, array $renderPlan): bool
    {
        if ($layer === 'environment') {
            return ! empty($renderPlan['world_environment']);
        }

        if ($layer === 'negative') {
            // Cấp Truth (continuity.prohibitions) — proxy thô, motion_negative_from_scene()
            // bên Python còn lọc thêm theo scene.subjects (không đo ở đây).
            return ! empty($renderPlan['continuity']['prohibitions'] ?? []);
        }

        foreach ($renderPlan['scenes'] ?? [] as $scene) {
            if ($this->sceneHasLayer($layer, $scene)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $scene
     */
    private function sceneHasLayer(string $layer, array $scene): bool
    {
        return match ($layer) {
            'objective', 'visual_style' => ! empty($scene[$layer]),
            'camera_path' => ! empty($scene['camera']['movement'] ?? null),
            'primary' => ! empty($scene['director_notes']['primary'] ?? null),
            'secondary' => ! empty($scene['director_notes']['secondary'] ?? null),
            'micro_physics' => ! empty($scene['director_notes']['micro_physics'] ?? null),
            default => false,
        };
    }
}
