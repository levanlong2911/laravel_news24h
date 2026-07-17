<?php

namespace App\Video\Timeline;

use App\Video\Intent\IntentSceneGraph;

/**
 * IntentSceneGraph → TimedSceneGraph. Đặt mỗi scene vào [start, end].
 *
 * SCHEDULER cơ học, KHÔNG phải EDITOR. Chia đều target cho số scene. Giữ nguyên
 * thứ tự. Deterministic. KHÔNG biết scene nói về gì, importance bao nhiêu, cảm
 * xúc thế nào — pacing là taste, thuộc Editorial (Phase 5).
 *
 * Ranh giới "không biết nội dung" đóng bằng thiết kế: phép tính ranh giới là
 * hàm THUẦN của (số scene, tổng thời lượng) — xem boundaries(). Nó không được
 * đưa scene content vào, nên không thể cân thời lượng theo importance kể cả muốn.
 */
final class TimelinePlanner
{
    public function plan(IntentSceneGraph $intents, float $targetSeconds): TimedSceneGraph
    {
        $count = count($intents->scenes);

        if ($count === 0) {
            return new TimedSceneGraph([], $targetSeconds);
        }

        $bounds = $this->boundaries($count, $targetSeconds);
        $timed  = [];

        foreach (array_values($intents->scenes) as $i => $intent) {
            $timed[] = new TimedScene($intent, new TimeRange($bounds[$i], $bounds[$i + 1]));
        }

        return new TimedSceneGraph($timed, $targetSeconds);
    }

    /**
     * n+1 mốc thời gian chia đều [0, target].
     *
     * Gapless BẮT BUỘC: scene i dùng bound[i]..bound[i+1], scene i+1 dùng
     * bound[i+1].. — hai bên chia sẻ ĐÚNG CÙNG một giá trị nên end===start tuyệt
     * đối, không lệ thuộc sai số float. Tính `target*i/n` (nhân trước chia) để
     * mốc cuối `target*n/n` ra đúng target.
     *
     * Chữ ký chỉ nhận int + float — không có đường nào chạm scene content.
     *
     * @return list<float>
     */
    private function boundaries(int $count, float $target): array
    {
        $bounds = [];

        for ($i = 0; $i <= $count; $i++) {
            $bounds[] = $target * $i / $count;
        }

        return $bounds;
    }
}
