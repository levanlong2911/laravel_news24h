<?php

namespace App\Video\Superyacht\Validation\Invariants;

use App\Video\Superyacht\Validation\ValidationReport;
use App\Video\Superyacht\Validation\Violation;

/**
 * INV-SS-01  tl_out > tl_in and tl_out − tl_in ≤ render_sec
 * INV-SS-02  tl_in, tl_out lie on the locked cut grid
 * INV-SS-03  reveal = H5 only on or after the hero beat; no "finished" set before A4
 * INV-SS-04  operational_context satisfies the canonical operational_limits
 */
final class ShotSpecInvariants implements InvariantSet
{
    public function __construct(
        private readonly string $heroBeat,
        private readonly float $gridTolerance,
    ) {
    }

    public function schema(): string
    {
        return 'ShotSpec';
    }

    public function check(array $doc, InvariantContext $ctx): ValidationReport
    {
        $v = [];
        $in = (float) $doc['tl_in'];
        $out = (float) $doc['tl_out'];

        if ($out <= $in) {
            $v[] = new Violation('INV-SS-01', "tl_out ({$out}) must be greater than tl_in ({$in})", '/tl_out');
        } elseif ($out - $in > (float) $doc['render_sec'] + 1e-9) {
            $v[] = new Violation('INV-SS-01', sprintf('timeline length %.3fs exceeds render_sec %ss', $out - $in, $doc['render_sec']), '/render_sec');
        }

        $grid = $ctx->cutGrid();
        if ($grid === null) {
            $v[] = new Violation('INV-SS-02', 'no locked cut grid (timing lock missing)', '/tl_in');
        } else {
            foreach (['tl_in' => $in, 'tl_out' => $out] as $field => $t) {
                if (! $this->onGrid($t, $grid)) {
                    $v[] = new Violation('INV-SS-02', "{$field} {$t} is not on the cut grid", "/{$field}");
                }
            }
        }

        $beat = (int) substr($doc['beat'], 1);
        $hero = (int) substr($this->heroBeat, 1);
        if (($doc['reveal'] ?? null) === 'H5' && $beat < $hero) {
            $v[] = new Violation('INV-SS-03', "reveal H5 used in {$doc['beat']}, before hero beat {$this->heroBeat}", '/reveal');
        }
        if (str_ends_with($doc['place'] ?? '', '_finished') && ($doc['act'] ?? null) !== 'A4') {
            $v[] = new Violation('INV-SS-03', "finished set {$doc['place']} used in {$doc['act']} (only allowed in A4)", '/place');
        }

        $canonical = $ctx->lockedCanonical();
        $op = $doc['operational_context'] ?? [];
        if (! empty($op['features_active'])) {
            if ($canonical === null) {
                $v[] = new Violation('INV-SS-04', 'no locked canonical design to check operational limits against', '/operational_context');
            } else {
                $v = [...$v, ...$this->checkLimits($op, $canonical['operational_limits'] ?? [])];
            }
        }

        return new ValidationReport($v);
    }

    /** @return array<int,Violation> */
    private function checkLimits(array $op, array $limits): array
    {
        $v = [];
        $byFeature = [];
        foreach ($limits as $limit) {
            $byFeature[$limit['feature']] = $limit['allowed_when'] ?? [];
        }

        foreach ($op['features_active'] as $feature) {
            $allowed = $byFeature[$feature] ?? null;
            if ($allowed === null) {
                continue; // no limit recorded for this feature
            }
            if (isset($allowed['max_speed_kn']) && $op['speed_kn'] > $allowed['max_speed_kn']) {
                $v[] = new Violation('INV-SS-04', "{$feature} allowed up to {$allowed['max_speed_kn']} kn, shot runs at {$op['speed_kn']} kn", '/operational_context/speed_kn');
            }
            if (! empty($allowed['at_anchor_only']) && empty($op['at_anchor'])) {
                $v[] = new Violation('INV-SS-04', "{$feature} is only allowed at anchor", '/operational_context/at_anchor');
            }
            if (isset($allowed['max_sea_state'], $op['sea_state']) && $op['sea_state'] > $allowed['max_sea_state']) {
                $v[] = new Violation('INV-SS-04', "{$feature} allowed up to sea state {$allowed['max_sea_state']}", '/operational_context/sea_state');
            }
        }

        return $v;
    }

    private function onGrid(float $t, array $grid): bool
    {
        foreach ($grid as $cut) {
            if (abs($t - (float) $cut) <= $this->gridTolerance) {
                return true;
            }
        }

        return false;
    }
}
