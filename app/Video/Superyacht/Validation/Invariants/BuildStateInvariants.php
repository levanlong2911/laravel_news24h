<?php

namespace App\Video\Superyacht\Validation\Invariants;

use App\Video\Superyacht\Validation\StateGeometryCompiler;
use App\Video\Superyacht\Validation\ValidationReport;
use App\Video\Superyacht\Validation\Violation;

/**
 * INV-BS-01  present_parts ∩ absent_parts = ∅
 * INV-BS-02  keys of surfaces_by_part ⊆ present_parts
 * INV-BS-03  parts present in state N are present, or deliberately removed, in state N+1 (sequence check;
 *            the BS05 tank model is a separate asset, so the step BS05 → BS06 is not compared)
 * INV-BS-04  compiled_text equals the compiler output for the current fields
 */
final class BuildStateInvariants implements InvariantSet
{
    /** @param array<int,string> $removableParts */
    public function __construct(
        private readonly StateGeometryCompiler $compiler,
        private readonly array $removableParts,
    ) {
    }

    public function schema(): string
    {
        return 'BuildState';
    }

    public function check(array $doc, InvariantContext $ctx, string $path = ''): ValidationReport
    {
        $v = [];
        $present = $doc['present_parts'] ?? [];
        $absent = $doc['absent_parts'] ?? [];
        $surfaces = (array) ($doc['surfaces_by_part'] ?? []);

        $overlap = array_values(array_intersect($present, $absent));
        if ($overlap !== []) {
            $v[] = new Violation('INV-BS-01', 'parts both present and absent: ' . implode(', ', $overlap), $path);
        }

        $extra = array_values(array_diff(array_keys($surfaces), $present));
        if ($extra !== []) {
            $v[] = new Violation('INV-BS-02', 'surface given for parts that are not present: ' . implode(', ', $extra), $path . '/surfaces_by_part');
        }

        $expected = $this->compiler->compile($doc);
        if (($doc['compiled_text'] ?? null) !== $expected) {
            $v[] = new Violation('INV-BS-04', "compiled_text differs from compiler output. Expected: \"{$expected}\"", $path . '/compiled_text');
        }

        return new ValidationReport($v);
    }

    /** INV-BS-03 over an ordered list of build states. @param array<int,array> $states */
    public function checkSequence(array $states, string $path = '/build_states'): ValidationReport
    {
        $v = [];
        for ($i = 1; $i < count($states); $i++) {
            $prev = $states[$i - 1];
            $cur = $states[$i];
            if (($prev['kind'] ?? null) === 'scale_model') {
                continue;
            }
            $lost = array_diff($prev['present_parts'] ?? [], $cur['present_parts'] ?? [], $this->removableParts);
            if ($lost !== []) {
                $v[] = new Violation(
                    'INV-BS-03',
                    sprintf('%s loses parts present in %s: %s', $cur['id'] ?? "#{$i}", $prev['id'] ?? '#' . ($i - 1), implode(', ', $lost)),
                    "{$path}/{$i}/present_parts"
                );
            }
        }

        return new ValidationReport($v);
    }
}
