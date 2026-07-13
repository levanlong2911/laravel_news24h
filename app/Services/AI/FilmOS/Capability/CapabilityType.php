<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Capability;

/**
 * All media transformation capabilities FilmOS understands.
 * Planners request capabilities — not provider names.
 * The registry maps capability → ranked provider list.
 */
enum CapabilityType: string
{
    case TEXT_TO_IMAGE  = 'text_to_image';
    case TEXT_TO_VIDEO  = 'text_to_video';   // Kling T2V — no image precursor required
    case IMAGE_TO_VIDEO = 'image_to_video';   // requires TEXT_TO_IMAGE precursor
    case LIPSYNC        = 'lipsync';
    case VOICE          = 'voice';
    case UPSCALE        = 'upscale';
    case FACE_SWAP      = 'face_swap';
    case TRANSITION     = 'transition';
    case MOTION         = 'motion';

    public function label(): string
    {
        return match ($this) {
            self::TEXT_TO_IMAGE  => 'Text → Image',
            self::TEXT_TO_VIDEO  => 'Text → Video',
            self::IMAGE_TO_VIDEO => 'Image → Video',
            self::LIPSYNC        => 'Lip Sync',
            self::VOICE          => 'Voice',
            self::UPSCALE        => 'Upscale',
            self::FACE_SWAP      => 'Face Swap',
            self::TRANSITION     => 'Transition',
            self::MOTION         => 'Motion',
        };
    }
}
