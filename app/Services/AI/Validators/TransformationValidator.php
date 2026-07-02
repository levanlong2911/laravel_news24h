<?php

namespace App\Services\AI\Validators;

use App\DTOs\TransformationDTO;

final class TransformationValidator
{
    private const VALID_STYLES   = ['cinematic', 'documentary', 'dynamic', 'elegant'];
    private const VALID_PALETTES = ['warm', 'cool', 'neutral', 'high_contrast'];
    private const VALID_PACING   = ['slow', 'medium', 'fast', 'dynamic'];

    /** @throws \InvalidArgumentException with human-readable message */
    public function validate(TransformationDTO $dto): void
    {
        $errors = [];

        if (trim($dto->theme) === '') {
            $errors[] = 'theme must not be empty';
        }

        if (!in_array($dto->style, self::VALID_STYLES, true)) {
            $errors[] = "style '{$dto->style}' not in [" . implode(',', self::VALID_STYLES) . ']';
        }

        if ($dto->duration < 5 || $dto->duration > 300) {
            $errors[] = "duration {$dto->duration}s out of range [5, 300]";
        }

        if (count($dto->emotionArc) < 2) {
            $errors[] = 'emotion_arc must have at least 2 entries';
        }

        if (!in_array($dto->colorPalette, self::VALID_PALETTES, true)) {
            $errors[] = "color_palette '{$dto->colorPalette}' not in [" . implode(',', self::VALID_PALETTES) . ']';
        }

        if (!in_array($dto->pacing, self::VALID_PACING, true)) {
            $errors[] = "pacing '{$dto->pacing}' not in [" . implode(',', self::VALID_PACING) . ']';
        }

        if ($errors !== []) {
            throw new \InvalidArgumentException('TransformationValidator: ' . implode('; ', $errors));
        }
    }
}
