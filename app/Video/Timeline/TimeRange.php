<?php

namespace App\Video\Timeline;

/**
 * Khoảng thời gian của một scene: [start, end) giây.
 *
 * Lưu start/end chứ KHÔNG lưu duration — duration là dẫn xuất (end - start).
 * Cùng nguyên tắc "đừng lưu thứ tính lại được". Và mọi thứ hạ nguồn (FFmpeg,
 * subtitle, audio, video editor) đều cần start/end tuyệt đối, không cần duration.
 * Khớp `timeline_slot` trong contract RenderPlan (§6).
 */
final class TimeRange
{
    public function __construct(
        public readonly float $start,
        public readonly float $end,
    ) {
    }

    public function duration(): float
    {
        return $this->end - $this->start;
    }
}
