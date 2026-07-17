<?php

namespace App\Video\Intent;

/**
 * Cảnh này có cần chuyển động không? Khớp `scene.motion_intent` trong contract.
 *
 * Thay thế content_type của kiến trúc cũ. Laravel chỉ nói mức chuyển động;
 * Python quyết định IMPLEMENTATION: NONE/LOW → Ken Burns rẻ, HIGH → Kling.
 * Laravel KHÔNG được biết Ken Burns hay Kling tồn tại. Xem §1.
 */
enum MotionIntent: string
{
    case None = 'NONE';
    case Low  = 'LOW';
    case High = 'HIGH';
}
