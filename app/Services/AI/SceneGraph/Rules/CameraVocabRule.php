<?php

namespace App\Services\AI\SceneGraph\Rules;

use App\Services\AI\SceneGraph\ShotSceneGraph;

/**
 * Validates string fields on CameraNode that are NOT covered by enums.
 *
 * CameraMove and CameraType are backed enums — they enforce vocab at
 * construction time. Only lensCode and height (still strings) need validation.
 */
final class CameraVocabRule implements SceneRule
{
    private const VALID_LENS_CODES = ['16', '24', '35', '50', '85', '135', '200'];

    private const VALID_HEIGHTS = [
        'eye-level', 'low', 'high', 'worm', 'bird', 'dutch',
        'aerial', 'low-angle', 'high-angle', 'ground-level',
    ];

    public function validate(ShotSceneGraph $graph): array
    {
        $errors = [];

        if (!in_array($graph->camera->lensCode, self::VALID_LENS_CODES, true)) {
            $errors[] = [
                'field'    => 'camera.lens_code',
                'expected' => implode('|', self::VALID_LENS_CODES),
                'actual'   => $graph->camera->lensCode,
            ];
        }

        if (!in_array($graph->camera->height, self::VALID_HEIGHTS, true)) {
            $errors[] = [
                'field'    => 'camera.height',
                'expected' => implode('|', self::VALID_HEIGHTS),
                'actual'   => $graph->camera->height,
            ];
        }

        return $errors;
    }

    public function name(): string
    {
        return 'camera_vocab';
    }
}
