<?php

namespace App\Video\Timeline;

use App\Video\Editorial\EditorialInterpreter;
use App\Video\Intent\IntentSceneGraph;

/**
 * IntentSceneGraph → TimedSceneGraph. Đặt mỗi scene vào [start, end].
 *
 * SCHEDULER cơ học, KHÔNG phải EDITOR — vẫn KHÔNG tự quyết pacing (không đọc
 * World Graph, không đọc importance của Act). Deterministic, giữ nguyên thứ tự.
 *
 * Pacing-theo-ScenePurpose (2026-07-23, Phase 5 đã hẹn trước — xem
 * EditorialInterpreter::durationWeightFor()): trọng số ĐẾN TỪ Editorial (cùng
 * "mù World Graph" như aestheticFor()), TimelinePlanner chỉ CHIA theo trọng số
 * đã cho — không tự phát minh trọng số, không đọc VerifiedWorldGraph. Trước
 * đây chia đều tuyệt đối (weight=1.0 mọi scene); giờ boundaries() nhận LIST
 * trọng số thay vì chỉ đếm số scene.
 */
final class TimelinePlanner
{
    public function __construct(
        private readonly EditorialInterpreter $editorial = new EditorialInterpreter(),
    ) {
    }

    public function plan(IntentSceneGraph $intents, float $targetSeconds): TimedSceneGraph
    {
        $scenes = array_values($intents->scenes);

        if ($scenes === []) {
            return new TimedSceneGraph([], $targetSeconds);
        }

        $weights = array_map(fn ($intent) => $this->editorial->durationWeightFor($intent->scene->purpose), $scenes);
        $bounds  = $this->boundaries($weights, $targetSeconds);
        $timed   = [];

        foreach ($scenes as $i => $intent) {
            $timed[] = new TimedScene($intent, new TimeRange($bounds[$i], $bounds[$i + 1]));
        }

        return new TimedSceneGraph($timed, $targetSeconds);
    }

    /**
     * n+1 mốc thời gian, chia theo TỈ LỆ trọng số (không còn đều tuyệt đối).
     *
     * Gapless BẮT BUỘC: scene i dùng bound[i]..bound[i+1], scene i+1 dùng
     * bound[i+1].. — hai bên chia sẻ ĐÚNG CÙNG một giá trị nên end===start
     * tuyệt đối, không lệ thuộc sai số float. Mốc cuối tính từ
     * `target * cumulative / totalWeight` với cumulative CUỐI CÙNG luôn bằng
     * totalWeight (không cộng dồn phân số riêng lẻ) nên ra đúng $target,
     * không tích luỹ sai số.
     *
     * @param list<float> $weights
     * @return list<float>
     */
    private function boundaries(array $weights, float $target): array
    {
        $totalWeight = array_sum($weights);
        $bounds      = [0.0];
        $cumulative  = 0.0;

        foreach ($weights as $weight) {
            $cumulative += $weight;
            $bounds[] = $target * $cumulative / $totalWeight;
        }

        return $bounds;
    }
}
