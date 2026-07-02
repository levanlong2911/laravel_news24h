<?php

namespace App\Services\AI\PromptCompiler\Libraries;

final class QualityLibrary
{
    public const VERSION = '1.0';

    private const TIERS = [
        'photoreal' => [
            'Photorealistic.',
            'Ultra realistic.',
            'Highly detailed.',
            'Professional photography.',
            'Shot on professional medium-format camera.',
            'Sharp focus.',
            'Natural materials.',
            'Realistic textures.',
            'Physically accurate lighting.',
            'Natural reflections.',
            'Premium cinematic composition.',
            '8K resolution.',
        ],
        'high' => [
            'Highly detailed.',
            'Professional photography.',
            'Sharp focus.',
            'Natural textures.',
            'Realistic materials.',
            'Cinematic composition.',
            'Professional color grading.',
            '4K quality.',
        ],
        'medium' => [
            'Stylized realistic.',
            'Detailed.',
            'Professional quality.',
            'Cinematic framing.',
        ],
        'low' => [
            'Artistic style.',
            'Stylized rendering.',
        ],
    ];

    public static function phrases(string $tier): array
    {
        return self::TIERS[$tier] ?? self::TIERS['high'];
    }
}
