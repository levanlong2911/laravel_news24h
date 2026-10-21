<?php

namespace App\Video\Superyacht\Validation\Invariants;

use App\Video\Superyacht\Validation\ValidationReport;
use App\Video\Superyacht\Validation\Violation;

/**
 * INV-CD-01  every deck has start_x < end_x
 * INV-CD-02  build_states contains BS05–BS12 exactly once each, in order
 * INV-CD-03  operational_limits[].feature refers to a known feature of the design
 * Also runs INV-BS-01..04 on every build state.
 */
final class CanonicalDesignInvariants implements InvariantSet
{
    /** @param array<string,string> $features feature => part */
    public function __construct(
        private readonly BuildStateInvariants $buildStates,
        private readonly array $features,
    ) {
    }

    public function schema(): string
    {
        return 'CanonicalDesign';
    }

    public function check(array $doc, InvariantContext $ctx): ValidationReport
    {
        $v = [];

        foreach ($doc['decks'] ?? [] as $i => $deck) {
            if (($deck['start_x'] ?? 0) >= ($deck['end_x'] ?? 0)) {
                $v[] = new Violation('INV-CD-01', sprintf('deck "%s": start_x must be < end_x', $deck['name'] ?? $i), "/decks/{$i}");
            }
        }

        $states = $doc['build_states'] ?? [];
        $ids = array_map(fn ($s) => $s['id'] ?? '?', $states);
        $expected = ['BS05', 'BS06', 'BS07', 'BS08', 'BS09', 'BS10', 'BS11', 'BS12'];
        if ($ids !== $expected) {
            $v[] = new Violation('INV-CD-02', 'build_states must be exactly ' . implode(',', $expected) . ' in order; got ' . implode(',', $ids), '/build_states');
        }

        $finished = [];
        foreach ($states as $s) {
            if (($s['id'] ?? null) === 'BS12') {
                $finished = $s['present_parts'] ?? [];
            }
        }
        foreach ($doc['operational_limits'] ?? [] as $i => $limit) {
            $feature = $limit['feature'] ?? '';
            if (! isset($this->features[$feature])) {
                $v[] = new Violation('INV-CD-03', "unknown feature \"{$feature}\"", "/operational_limits/{$i}/feature");
            } elseif (! in_array($this->features[$feature], $finished, true)) {
                $v[] = new Violation('INV-CD-03', "feature \"{$feature}\" needs part \"{$this->features[$feature]}\", which the finished yacht (BS12) does not have", "/operational_limits/{$i}/feature");
            }
        }

        $report = new ValidationReport($v);
        foreach ($states as $i => $state) {
            $report = $report->merge($this->buildStates->check($state, $ctx, "/build_states/{$i}"));
        }

        return $report->merge($this->buildStates->checkSequence($states));
    }
}
