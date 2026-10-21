<?php

namespace App\Video\Superyacht\Validation\Invariants;

use App\Video\Superyacht\Support\BuildStates;
use App\Video\Superyacht\Validation\ValidationReport;
use App\Video\Superyacht\Validation\Violation;

/**
 * INV-CN-01  from_state ≤ until_state when until_state is set
 * INV-CN-02  its part is in present_parts of every state from from_state to until_state
 * INV-CN-03  canonical_revision equals the locked canonical revision
 */
final class ConstraintInvariants implements InvariantSet
{
    public function __construct(private readonly BuildStates $states)
    {
    }

    public function schema(): string
    {
        return 'Constraint';
    }

    public function check(array $doc, InvariantContext $ctx): ValidationReport
    {
        $v = [];
        $from = $doc['from_state'];
        $until = $doc['until_state'] ?? null;

        if ($until !== null && $this->states->compare($from, $until) > 0) {
            $v[] = new Violation('INV-CN-01', "from_state {$from} is after until_state {$until}", '/until_state');

            return new ValidationReport($v);
        }

        $canonical = $ctx->lockedCanonical();
        if ($canonical === null) {
            $v[] = new Violation('INV-CN-03', 'no locked canonical design exists', '/canonical_revision');

            return new ValidationReport($v);
        }

        if (($doc['canonical_revision'] ?? null) !== ($canonical['revision'] ?? null)) {
            $v[] = new Violation('INV-CN-03', sprintf('canonical_revision %s ≠ locked revision %s', $doc['canonical_revision'] ?? 'null', $canonical['revision'] ?? 'null'), '/canonical_revision');
        }

        if (! empty($doc['part'])) {
            $byId = [];
            foreach ($canonical['build_states'] ?? [] as $s) {
                $byId[$s['id']] = $s['present_parts'] ?? [];
            }
            $missing = [];
            foreach ($this->states->range($from, $until) as $state) {
                if (! in_array($doc['part'], $byId[$state] ?? [], true)) {
                    $missing[] = $state;
                }
            }
            if ($missing !== []) {
                $v[] = new Violation('INV-CN-02', "part \"{$doc['part']}\" is not present in " . implode(', ', $missing), '/part');
            }
        }

        return new ValidationReport($v);
    }
}
