<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\QA;

/**
 * One QA check over the narrative. Open/Closed: new rules are added to the
 * Auditor's list without modifying the Auditor (same pattern as ProjectionHandler).
 *
 * Rules receive a NarrativeAuditContext — never raw collaborators — so this
 * signature is FROZEN: future inputs (asset registry, planner metadata, …)
 * are added to the context, not to this method.
 *
 * The context deliberately exposes BOTH sources:
 *   timeline() — raw events, including what the projection tolerated and
 *                collapsed (duplicate introductions, orphan emotions)
 *   state()    — the projected truth (dangling refs, missing cameras)
 *
 * Rules are READ-ONLY: never append to the timeline (Single Writer stays
 * TimelineRecorder), never mutate state, never throw for narrative problems —
 * report them as findings.
 */
interface NarrativeRule
{
    /** Stable identifier of this rule, e.g. "camera.missing". Frozen once shipped. */
    public function ruleId(): string;

    /**
     * Yields nothing when the narrative is clean. Returning iterable (not array)
     * lets rules `yield` findings as they scan — no per-rule array building,
     * and the API holds unchanged whether there are 6 rules or 1000.
     *
     * @return iterable<NarrativeFinding>
     */
    public function check(NarrativeAuditContext $context): iterable;
}
