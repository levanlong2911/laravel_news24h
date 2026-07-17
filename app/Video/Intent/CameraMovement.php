<?php

namespace App\Video\Intent;

/** Chuyển động máy quay — INTENT. Khớp `camera.movement` trong contract (§6). */
enum CameraMovement: string
{
    case Static  = 'STATIC';
    case Orbit   = 'ORBIT';
    case PushIn  = 'PUSH_IN';
    case PullOut = 'PULL_OUT';
    case Pan     = 'PAN';
    case Track   = 'TRACK';
}
