<?php

namespace App\Services\AI\Validators;

use App\DTOs\BeatDTO;
use App\DTOs\StoryDTO;
use App\DTOs\TransformationDTO;

final class StoryValidator
{
    private const DURATION_TOLERANCE = 0.5; // seconds
    private const HOOK_EMOTIONS = ['hook', 'anticipation', 'mystery', 'tension', 'curiosity'];

    /** @throws \InvalidArgumentException */
    public function validate(StoryDTO $story, TransformationDTO $transformation): void
    {
        $errors = [];

        if ($story->beatCount() === 0) {
            $errors[] = 'story must have at least 1 beat';
        }

        // Duration check: sum of beat durations must equal target ±tolerance
        $totalDuration = $story->totalDuration;
        $target        = $transformation->duration;
        if (abs($totalDuration - $target) > self::DURATION_TOLERANCE) {
            $errors[] = "total beat duration {$totalDuration}s differs from target {$target}s by more than " . self::DURATION_TOLERANCE . 's';
        }

        foreach ($story->beats() as $beat) {
            if ($beat->duration <= 0) {
                $errors[] = "beat {$beat->beatNumber} has zero or negative duration";
            }

            if (!in_array($beat->informationType, BeatDTO::INFORMATION_TYPES, true)) {
                $errors[] = "beat {$beat->beatNumber} has invalid information_type '{$beat->informationType}'; must be one of: " . implode(', ', BeatDTO::INFORMATION_TYPES);
            }

            if (!in_array($beat->visualPriority, BeatDTO::VISUAL_PRIORITIES, true)) {
                $errors[] = "beat {$beat->beatNumber} has invalid visual_priority '{$beat->visualPriority}'; must be one of: " . implode(', ', BeatDTO::VISUAL_PRIORITIES);
            }
        }

        // First beat must carry a hook emotion
        if ($story->beatCount() > 0) {
            $firstEmotion = strtolower($story->beats()[0]->emotion);
            $isHook = array_reduce(self::HOOK_EMOTIONS, fn ($carry, $h) => $carry || str_contains($firstEmotion, $h), false);
            if (!$isHook) {
                $errors[] = "first beat emotion '{$firstEmotion}' should signal a hook (anticipation/mystery/tension/curiosity)";
            }
        }

        if ($errors !== []) {
            throw new \InvalidArgumentException('StoryValidator: ' . implode('; ', $errors));
        }
    }
}
