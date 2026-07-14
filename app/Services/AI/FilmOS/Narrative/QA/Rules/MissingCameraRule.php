<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\QA\Rules;

use App\Services\AI\FilmOS\Narrative\QA\FindingCategory;
use App\Services\AI\FilmOS\Narrative\QA\FindingSeverity;
use App\Services\AI\FilmOS\Narrative\QA\NarrativeAuditContext;
use App\Services\AI\FilmOS\Narrative\QA\NarrativeFinding;
use App\Services\AI\FilmOS\Narrative\QA\NarrativeRule;

/**
 * A planned shot has no camera configuration.
 *
 * The only blocking=true rule in v1: PromptCompiler cannot compile a shot
 * without knowing how it is framed — LensType is mandatory on
 * CameraConfiguration for the same reason ("Planner must decide, Compiler
 * must not guess").
 */
final class MissingCameraRule implements NarrativeRule
{
    public const CODE = 'D4.NO_CAMERA';

    public function ruleId(): string
    {
        return 'camera.missing';
    }

    public function check(NarrativeAuditContext $context): iterable
    {
        foreach ($context->story()->allShots() as $shot) {
            if ($context->scene()->getCamera($shot->ordinal) === null) {
                yield new NarrativeFinding(
                    severity:  FindingSeverity::ERROR,
                    category:  FindingCategory::CAMERA,
                    code:      self::CODE,
                    message:   "Shot '{$shot->shotId}' (ordinal {$shot->ordinal}) has no camera configuration.",
                    ruleId:    $this->ruleId(),
                    blocking:  true,
                    subjectId: $shot->shotId,
                    ordinal:   $shot->ordinal,
                );
            }
        }
    }
}
