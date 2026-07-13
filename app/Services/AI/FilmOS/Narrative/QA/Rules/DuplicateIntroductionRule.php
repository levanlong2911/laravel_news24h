<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\QA\Rules;

use App\Services\AI\FilmOS\Narrative\QA\FindingCategory;
use App\Services\AI\FilmOS\Narrative\QA\FindingSeverity;
use App\Services\AI\FilmOS\Narrative\QA\NarrativeAuditContext;
use App\Services\AI\FilmOS\Narrative\QA\NarrativeFinding;
use App\Services\AI\FilmOS\Narrative\QA\NarrativeRule;
use App\Services\AI\FilmOS\Narrative\Timeline\Events\CharacterIntroducedEvent;

/**
 * A character was introduced more than once (CharacterIntroducedEvent invariant).
 *
 * The projection applies last-write-wins so duplicates are invisible in state —
 * only the raw timeline still holds the evidence.
 */
final class DuplicateIntroductionRule implements NarrativeRule
{
    public const CODE = 'D2.DUP_INTRO';

    public function ruleId(): string
    {
        return 'character.duplicate_introduction';
    }

    public function check(NarrativeAuditContext $context): array
    {
        /** @var array<string, int> */
        $counts = [];

        foreach ($context->timeline()->events() as $event) {
            if ($event instanceof CharacterIntroducedEvent) {
                $counts[$event->profile->id] = ($counts[$event->profile->id] ?? 0) + 1;
            }
        }

        $findings = [];
        foreach ($counts as $characterId => $count) {
            if ($count > 1) {
                $findings[] = new NarrativeFinding(
                    severity:  FindingSeverity::WARNING,
                    category:  FindingCategory::CHARACTER,
                    code:      self::CODE,
                    message:   "Character '{$characterId}' was introduced {$count} times; expected exactly once.",
                    ruleId:    $this->ruleId(),
                    blocking:  false,
                    subjectId: $characterId,
                );
            }
        }

        return $findings;
    }
}
