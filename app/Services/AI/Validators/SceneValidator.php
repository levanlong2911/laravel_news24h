<?php

namespace App\Services\AI\Validators;

use App\DTOs\SceneDTO;
use App\DTOs\ShotDTO;

final class SceneValidator
{
    private const DURATION_TOLERANCE   = 0.5;
    private const VALID_CAM     = ['WIDE','MEDIUM','CLOSE','MACRO','ORBITAL','TRACKING','AERIAL','POV'];
    private const VALID_MOTION  = ['none','low','medium','high'];
    private const VALID_REALISM = ['low','medium','high','photoreal'];
    // Rule 1: Planner must not output any provider name
    private const FORBIDDEN_PROVIDER_TERMS = ['fal_flux','fal.ai','fal-ai','flux','kling','veo','ideogram','leonardo','replicate','dalle','midjourney','firefly'];

    /** @throws \InvalidArgumentException */
    public function validate(SceneDTO $scene): void
    {
        $errors = [];

        if ($scene->shotCount() === 0) {
            $errors[] = "scene {$scene->sceneNumber} '{$scene->title}' has no shots";
        }

        if ($scene->duration <= 0) {
            $errors[] = "scene {$scene->sceneNumber} has zero duration";
        }

        // Shot duration sum should be close to scene duration
        $shotTotal = $scene->totalShotDuration();
        if (abs($shotTotal - $scene->duration) > self::DURATION_TOLERANCE) {
            $errors[] = "scene {$scene->sceneNumber}: shot duration sum {$shotTotal}s differs from scene duration {$scene->duration}s";
        }

        foreach ($scene->shots() as $shot) {
            $this->validateShot($shot, $scene->sceneNumber, $errors);
        }

        if ($errors !== []) {
            throw new \InvalidArgumentException('SceneValidator (scene ' . $scene->sceneNumber . '): ' . implode('; ', $errors));
        }
    }

    private function validateShot(ShotDTO $shot, int $sceneNum, array &$errors): void
    {
        $prefix = "scene {$sceneNum} shot {$shot->shotOrder}";

        if (!in_array($shot->cam, self::VALID_CAM, true)) {
            $errors[] = "{$prefix}: cam '{$shot->cam}' not valid";
        }

        if (!in_array($shot->motionLevel, self::VALID_MOTION, true)) {
            $errors[] = "{$prefix}: motion_level '{$shot->motionLevel}' not valid";
        }

        if (!in_array($shot->realism, self::VALID_REALISM, true)) {
            $errors[] = "{$prefix}: realism '{$shot->realism}' not valid";
        }

        if ($shot->dur < 0.5) {
            $errors[] = "{$prefix}: duration {$shot->dur}s below minimum 0.5s";
        }

        // Rule 1 enforcement: no provider names anywhere in shot data
        $shotJson = strtolower(json_encode($shot->toArray()));
        foreach (self::FORBIDDEN_PROVIDER_TERMS as $term) {
            if (str_contains($shotJson, $term)) {
                $errors[] = "{$prefix}: RULE VIOLATION — provider name '{$term}' found in shot DSL (Planners must not know providers)";
            }
        }
    }
}
