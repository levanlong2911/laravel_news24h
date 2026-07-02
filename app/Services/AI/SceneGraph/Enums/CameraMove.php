<?php

namespace App\Services\AI\SceneGraph\Enums;

enum CameraMove: string
{
    case STATIC = 'STATIC';
    case P1     = 'P1';   // Push in
    case P2     = 'P2';   // Pull out
    case D1     = 'D1';   // Dolly right
    case D2     = 'D2';   // Dolly left
    case O1     = 'O1';   // Orbital clockwise
    case O2     = 'O2';   // Orbital counterclockwise
    case H1     = 'H1';   // Handheld
    case T1     = 'T1';   // Tilt up
    case T2     = 'T2';   // Tilt down

    public static function fromDsl(string $code): self
    {
        return self::tryFrom(strtoupper($code)) ?? self::STATIC;
    }

    public function isMoving(): bool
    {
        return $this !== self::STATIC;
    }
}
