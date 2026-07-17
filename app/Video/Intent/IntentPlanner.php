<?php

namespace App\Video\Intent;

use App\Video\Scene\SceneGraph;
use App\Video\Scene\ScenePurpose;
use App\Video\Scene\SemanticScene;

/**
 * SceneGraph → IntentSceneGraph. Bồi ý đồ máy quay + chuyển động cho mỗi scene.
 *
 * RANH GIỚI BẢO ĐẢM BỞI TYPE SYSTEM: planner này CHỈ nhận SceneGraph, KHÔNG
 * nhận VerifiedWorldGraph. Nó không có đường nào chạm tới EntityType, Evidence
 * hay bài báo. Nên camera KHÔNG THỂ phụ thuộc chủ đề — nó chỉ suy được từ
 * ScenePurpose. Đây là bảo đảm mạnh hơn Architecture Test: không phải "đừng
 * làm" mà là "không làm được".
 *
 * Camera grammar là ngôn ngữ điện ảnh, độc lập chủ đề: "establishing shot thì
 * wide" đúng cho yacht, sư tử, nhà máy. Deterministic, không AI.
 *
 * CHƯA làm: lighting, physics, environment — vướng Truth + Editorial taste,
 * để Phase 5. Xem docs/video/ARCHITECTURE.md.
 */
final class IntentPlanner
{
    public function plan(SceneGraph $scenes): IntentSceneGraph
    {
        $intents = [];

        foreach ($scenes->scenes as $scene) {
            $intents[] = new IntentScene(
                $scene,
                $this->cameraFor($scene),
                $this->motionFor($scene->purpose),
            );
        }

        return new IntentSceneGraph($intents);
    }

    /**
     * Chú ý chữ ký: hàm chỉ đọc `purpose` cho grammar; `target` lấy từ subject
     * đã có sẵn của scene. KHÔNG có tham số EntityType — không thể branch domain.
     */
    private function cameraFor(SemanticScene $scene): CameraIntent
    {
        [$framing, $movement, $speed] = $this->grammarFor($scene->purpose);

        return new CameraIntent($framing, $movement, $speed, $scene->subjectIds[0]);
    }

    /**
     * @return array{0: CameraFraming, 1: CameraMovement, 2: CameraSpeed}
     */
    private function grammarFor(ScenePurpose $purpose): array
    {
        return match ($purpose) {
            // Toàn cảnh, vòng quanh chậm — dựng bối cảnh.
            ScenePurpose::Establish  => [CameraFraming::Wide,   CameraMovement::Orbit,   CameraSpeed::Slow],
            // Toàn cảnh, tiến vào — hé lộ chủ thể/mối liên hệ.
            ScenePurpose::Reveal     => [CameraFraming::Wide,   CameraMovement::PushIn,  CameraSpeed::Slow],
            // Cận cảnh soi chi tiết — gần như tĩnh.
            ScenePurpose::Detail     => [CameraFraming::Detail, CameraMovement::PushIn,  CameraSpeed::Slow],
            // Bám theo hành động — nhanh.
            ScenePurpose::Action     => [CameraFraming::Medium, CameraMovement::Track,   CameraSpeed::Fast],
            // Quá trình — bám theo, tốc độ vừa.
            ScenePurpose::Process    => [CameraFraming::Medium, CameraMovement::Track,   CameraSpeed::Medium],
            // So sánh — trên cao, lùi ra để thấy cả hai.
            ScenePurpose::Comparison => [CameraFraming::Aerial, CameraMovement::PullOut, CameraSpeed::Slow],
            // Kết — lùi ra, thu toàn cảnh.
            ScenePurpose::Resolution => [CameraFraming::Aerial, CameraMovement::PullOut, CameraSpeed::Slow],
        };
    }

    private function motionFor(ScenePurpose $purpose): MotionIntent
    {
        return match ($purpose) {
            ScenePurpose::Detail                        => MotionIntent::None,
            ScenePurpose::Action                        => MotionIntent::High,
            ScenePurpose::Establish, ScenePurpose::Reveal,
            ScenePurpose::Process, ScenePurpose::Comparison,
            ScenePurpose::Resolution                    => MotionIntent::Low,
        };
    }
}
