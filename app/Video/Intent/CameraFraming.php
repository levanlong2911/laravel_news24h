<?php

namespace App\Video\Intent;

/**
 * Khung hình — INTENT, không phải thấu kính. Khớp `camera.framing` trong
 * contract RenderPlan (§6). Python mới dịch WIDE → "24mm", CLOSE → "85mm".
 */
enum CameraFraming: string
{
    case Wide   = 'WIDE';
    case Medium = 'MEDIUM';
    case Close  = 'CLOSE';
    case Detail = 'DETAIL';
    case Aerial = 'AERIAL';
}
