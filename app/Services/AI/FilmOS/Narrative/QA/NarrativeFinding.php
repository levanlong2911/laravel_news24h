<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\QA;

/**
 * One QA observation about the narrative — knowledge, never a decision.
 *
 * INVARIANT: immutable. Rules create new findings; no rule ever mutates
 * a finding produced by another rule. QA stays fully functional.
 *
 * $code is a STABLE machine identifier (e.g. "D2.DUP_INTRO") — benchmark and
 * learning count by code, never by parsing $message. Codes are frozen once
 * shipped; a changed meaning requires a new code.
 *
 * $ruleId names the rule that produced this finding (e.g. "camera.missing").
 *
 * $blocking is ORTHOGONAL to severity: it states whether PromptCompiler can
 * physically produce a correct shot from this state (impact description),
 * NOT whether the pipeline should stop (consumer's decision). An ERROR with
 * blocking=false is legal — e.g. an orphan emotion is a planner bug worth
 * an ERROR, but the projection already dropped it, so compilation proceeds.
 */
final class NarrativeFinding
{
    public function __construct(
        public readonly FindingSeverity $severity,
        public readonly FindingCategory $category,
        public readonly string          $code,
        public readonly string          $message,
        public readonly string          $ruleId,
        public readonly bool            $blocking  = false,
        public readonly ?string         $subjectId = null,  // characterId, nodeId, objectId…
        public readonly ?int            $ordinal   = null,  // shot ordinal, when location is known
    ) {}
}
