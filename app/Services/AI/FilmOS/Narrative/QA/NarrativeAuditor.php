<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\QA;

use App\Services\AI\FilmOS\Narrative\Timeline\NarrativeState;
use App\Services\AI\FilmOS\Narrative\Timeline\SemanticTimeline;

/**
 * Runs all narrative QA rules and aggregates their findings into a report.
 *
 * DETERMINISM: rules run in the exact order given to the constructor —
 * never container/registration order. The canonical order is defined once
 * in FilmOSServiceProvider (Character → World → Scene → Camera). Same
 * input always produces the same report, in the same order, which makes
 * reports snapshot-testable.
 *
 * The Auditor is knowledge, not decision: it never mutates state, never
 * appends events, never throws for narrative problems, never auto-fixes.
 *
 * Note: ProjectionPriority::QA (500) is NOT used here — that constant is
 * reserved for projection *handlers* if QA-domain events ever need to be
 * projected onto NarrativeState. Audit rules are not projection handlers.
 */
final class NarrativeAuditor
{
    /** @var NarrativeRule[] */
    private readonly array $rules;

    /** @param NarrativeRule[] $rules run in this exact order */
    public function __construct(array $rules)
    {
        $this->rules = array_values($rules);
    }

    public function audit(SemanticTimeline $timeline, NarrativeState $state): NarrativeAuditReport
    {
        $context  = new NarrativeAuditContext($timeline, $state);
        $findings = [];

        foreach ($this->rules as $rule) {
            foreach ($rule->check($context) as $finding) {
                $findings[] = $finding;
            }
        }

        return new NarrativeAuditReport($findings);
    }
}
