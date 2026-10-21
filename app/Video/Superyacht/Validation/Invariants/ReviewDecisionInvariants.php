<?php

namespace App\Video\Superyacht\Validation\Invariants;

use App\Video\Superyacht\Validation\ValidationReport;
use App\Video\Superyacht\Validation\Violation;

/**
 * INV-RD-01  decided_by is a human user for reasons DESIGN_LOCK and PUBLISH;
 *            the decision is allowed for the item_type
 */
final class ReviewDecisionInvariants implements InvariantSet
{
    /**
     * @param array<string,array<int,string>> $decisionsByType
     * @param array<int,string> $humanOnlyReasons
     */
    public function __construct(
        private readonly array $decisionsByType,
        private readonly array $humanOnlyReasons,
    ) {
    }

    public function schema(): string
    {
        return 'ReviewDecision';
    }

    public function check(array $doc, InvariantContext $ctx): ValidationReport
    {
        $v = [];

        if (in_array($doc['reason'], $this->humanOnlyReasons, true) && ! $ctx->isHumanUser($doc['decided_by'])) {
            $v[] = new Violation('INV-RD-01', "{$doc['reason']} must be decided by a human user, got \"{$doc['decided_by']}\"", '/decided_by');
        }

        $allowed = $this->decisionsByType[$doc['item_type']] ?? [];
        if (! in_array($doc['decision'], $allowed, true)) {
            $v[] = new Violation('INV-RD-01', "decision {$doc['decision']} not allowed for {$doc['item_type']} (allowed: " . implode(', ', $allowed) . ')', '/decision');
        }

        return new ValidationReport($v);
    }
}
