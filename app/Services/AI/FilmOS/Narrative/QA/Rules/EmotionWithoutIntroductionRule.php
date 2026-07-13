<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\QA\Rules;

use App\Services\AI\FilmOS\Narrative\QA\FindingCategory;
use App\Services\AI\FilmOS\Narrative\QA\FindingSeverity;
use App\Services\AI\FilmOS\Narrative\QA\NarrativeAuditContext;
use App\Services\AI\FilmOS\Narrative\QA\NarrativeFinding;
use App\Services\AI\FilmOS\Narrative\QA\NarrativeRule;
use App\Services\AI\FilmOS\Narrative\Timeline\Events\CharacterEmotionChangedEvent;
use App\Services\AI\FilmOS\Narrative\Timeline\Events\CharacterIntroducedEvent;

/**
 * An emotion was recorded for a character that was never introduced.
 *
 * The projection silently drops orphan emotions at build() time — the evidence
 * only exists in the raw timeline, which is why this rule reads events.
 */
final class EmotionWithoutIntroductionRule implements NarrativeRule
{
    public const CODE = 'D2.EMOTION_WITHOUT_INTRO';

    public function ruleId(): string
    {
        return 'character.emotion_without_introduction';
    }

    public function check(NarrativeAuditContext $context): array
    {
        $introduced = [];
        $orphans    = [];

        foreach ($context->timeline()->events() as $event) {
            if ($event instanceof CharacterIntroducedEvent) {
                $introduced[$event->profile->id] = true;
            }
        }

        foreach ($context->timeline()->events() as $event) {
            if ($event instanceof CharacterEmotionChangedEvent
                && !isset($introduced[$event->characterId])
            ) {
                // ERROR but not blocking: the projection already dropped the
                // orphan emotion, so the compile can proceed — the finding
                // records the planner bug, the consumer decides what to do.
                $orphans[] = new NarrativeFinding(
                    severity:  FindingSeverity::ERROR,
                    category:  FindingCategory::CHARACTER,
                    code:      self::CODE,
                    message:   "Emotion recorded for character '{$event->characterId}' who was never introduced.",
                    ruleId:    $this->ruleId(),
                    blocking:  false,
                    subjectId: $event->characterId,
                    ordinal:   $event->shotOrdinal(),
                );
            }
        }

        return $orphans;
    }
}
