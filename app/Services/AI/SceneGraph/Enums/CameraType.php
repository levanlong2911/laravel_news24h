<?php

namespace App\Services\AI\SceneGraph\Enums;

enum CameraType: string
{
    case AERIAL   = 'AERIAL';
    case TRACKING = 'TRACKING';
    case ORBITAL  = 'ORBITAL';
    case WIDE     = 'WIDE';
    case MEDIUM   = 'MEDIUM';
    case CLOSE    = 'CLOSE';
    case MACRO    = 'MACRO';
    case POV      = 'POV';

    public static function fromDsl(string $code): self
    {
        return self::tryFrom(strtoupper($code)) ?? self::MEDIUM;
    }

    /**
     * Height implied by this camera type — empty means no override (DirectorPlanner decides).
     */
    public function impliedHeight(): string
    {
        return match ($this) {
            self::AERIAL => 'aerial',
            self::MACRO  => 'ground-level',
            self::POV    => 'eye-level',
            default      => '',
        };
    }

    /**
     * Default lens code (numeric, no mm suffix) for this camera type.
     * Overridden by DirectorPlanner's CAM_LENS when shot is compiled.
     */
    public function defaultLensCode(): string
    {
        return match ($this) {
            self::AERIAL   => '35',
            self::TRACKING => '85',
            self::ORBITAL  => '50',
            self::WIDE     => '24',
            self::MEDIUM   => '50',
            self::CLOSE    => '85',
            self::MACRO    => '135',
            self::POV      => '35',
        };
    }
}
