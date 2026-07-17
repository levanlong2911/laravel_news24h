<?php

namespace App\Video\Intent;

use App\Video\Scene\SemanticScene;

/**
 * Một SemanticScene đã bồi thêm ý đồ máy quay và chuyển động.
 *
 * Đây là REFINEMENT của Scene, không phải type song song — Intent hợp lệ khi
 * dựng trên Scene. Vẫn CHƯA có lighting/physics/environment: chúng vướng Truth
 * (bài báo có nói không?) và Editorial taste, để Phase 5. Vẫn CHƯA có prompt:
 * đó là Python.
 */
final class IntentScene
{
    public function __construct(
        public readonly SemanticScene $scene,
        public readonly CameraIntent $camera,
        public readonly MotionIntent $motion,
    ) {
    }
}
