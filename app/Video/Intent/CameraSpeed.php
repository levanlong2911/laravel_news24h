<?php

namespace App\Video\Intent;

/** Tốc độ chuyển động máy quay. Khớp `camera.speed` trong contract (§6). */
enum CameraSpeed: string
{
    case Slow   = 'SLOW';
    case Medium = 'MEDIUM';
    case Fast   = 'FAST';
}
