<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\QA\Rules;

use App\Services\AI\FilmOS\Narrative\QA\FindingCategory;
use App\Services\AI\FilmOS\Narrative\QA\FindingSeverity;
use App\Services\AI\FilmOS\Narrative\QA\NarrativeAuditContext;
use App\Services\AI\FilmOS\Narrative\QA\NarrativeFinding;
use App\Services\AI\FilmOS\Narrative\QA\NarrativeRule;

/**
 * A character's worldObjectRef points at a world object that does not exist.
 * The cross-reference contract (D2 → D3 by string) only works if QA verifies it.
 */
final class DanglingCharacterWorldRefRule implements NarrativeRule
{
    public const CODE = 'D2.DANGLING_WORLD_REF';

    public function ruleId(): string
    {
        return 'character.dangling_world_ref';
    }

    public function check(NarrativeAuditContext $context): iterable
    {
        foreach ($context->characters()->allCharacters() as $characterId => $memory) {
            $ref = $memory->profile->worldObjectRef;

            if ($ref !== null && !$context->world()->hasObject($ref)) {
                // ERROR but not blocking: the shot still compiles; the broken
                // reference means world context for this character is missing.
                yield new NarrativeFinding(
                    severity:  FindingSeverity::ERROR,
                    category:  FindingCategory::CHARACTER,
                    code:      self::CODE,
                    message:   "Character '{$characterId}' references world object '{$ref}' which does not exist.",
                    ruleId:    $this->ruleId(),
                    blocking:  false,
                    subjectId: $characterId,
                );
            }
        }
    }
}
