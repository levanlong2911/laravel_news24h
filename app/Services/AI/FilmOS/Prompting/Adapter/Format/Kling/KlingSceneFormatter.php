<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompting\Adapter\Format\Kling;

use App\Services\AI\FilmOS\Narrative\Scene\CameraConfiguration;
use App\Services\AI\FilmOS\Prompting\Adapter\Format\SlotFormatter;
use App\Services\AI\FilmOS\Prompting\Plan\PlanSlot;

/**
 * Where the camera is and what the world looks like.
 *
 * Camera comes out as tags, not prose — "close-up, 85mm, handheld" reads better
 * to Kling than a sentence describing the same setup.
 */
final class KlingSceneFormatter implements SlotFormatter
{
    use JoinsPhrases;

    private const SHOT_TYPE = [
        'establishing'     => 'wide establishing shot',
        'wide'             => 'wide shot',
        'medium'           => 'medium shot',
        'close_up'         => 'close-up',
        'extreme_close_up' => 'extreme close-up',
        'two_shot'         => 'two-shot',
        'insert'           => 'insert shot',
    ];

    private const LENS = ['wide' => '24mm', 'normal' => '35mm', 'telephoto' => '85mm'];

    private const ANGLE = [
        'eye_level'     => '',   // the default framing — saying it wastes words
        'high'          => 'high angle',
        'low'           => 'low angle',
        'dutch'         => 'dutch tilt',
        'birds_eye'     => "bird's-eye view",
        'worms_eye'     => "worm's-eye view",
        'over_shoulder' => 'over-the-shoulder',
    ];

    private const MOVEMENT = [
        'static'   => 'locked-off',
        'pan'      => 'panning',
        'tilt'     => 'tilting up',
        'tracking' => 'tracking',
        'dolly'    => 'dolly move',
        'zoom'     => 'zoom',
        'handheld' => 'handheld',
    ];

    public function slots(): array
    {
        return [PlanSlot::CAMERA, PlanSlot::ENVIRONMENT];
    }

    public function format(PlanSlot $slot, mixed $payload): string
    {
        return match ($slot) {
            PlanSlot::CAMERA      => $this->camera($payload),
            PlanSlot::ENVIRONMENT => $this->environment($payload),
            default               => '',
        };
    }

    private function camera(CameraConfiguration $cam): string
    {
        return $this->sentence(implode(', ', array_filter([
            self::SHOT_TYPE[$cam->shotType->value] ?? $cam->shotType->value,
            self::LENS[$cam->lens->value]          ?? $cam->lens->value,
            self::ANGLE[$cam->angle->value]        ?? '',
            self::MOVEMENT[$cam->movement->value]  ?? $cam->movement->value,
        ])));
    }

    /** @param array<string, string> $details factKey => value */
    private function environment(array $details): string
    {
        $phrases = [];
        foreach ($details as $key => $value) {
            // The fact KEY supplies the noun the value is missing: 'crowd' =>
            // 'roaring' is only "roaring" until the key makes it a roaring crowd.
            $phrases[$this->envPhrase((string) $key, (string) $value)] = true;
        }
        return $phrases === [] ? '' : $this->sentence(implode(', ', array_keys($phrases)));
    }

    private function envPhrase(string $key, string $value): string
    {
        return match ($key) {
            'crowd' => "{$value} crowd",
            'light' => "{$value} light",
            default => $value,
        };
    }
}
