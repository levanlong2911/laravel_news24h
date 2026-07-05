<?php

namespace App\Services\AI\AFOS\Compiler\Validators;

use App\Services\AI\AFOS\Compiler\Diagnostics\DiagnosticBag;
use App\Services\AI\AFOS\Compiler\Diagnostics\DiagnosticCode;
use App\Services\AI\AFOS\Ir\ShotGoalIR;

/**
 * ShotGoalIRValidator — validates ShotGoalIR before Tier 1 passes run.
 *
 * Codes:
 *   AFOS1001 ERROR   — durationSec ≤ 0: physically impossible, compilation aborted
 *   AFOS1002 WARNING — durationSec < 1s: below practical minimum
 *   AFOS1003 WARNING — durationSec > 10s: Kling clamps at 10s
 *   AFOS1004 WARNING — energy outside [0,1]: planner produced out-of-range value
 *   AFOS1005 HINT    — goalTarget empty: EntityExtractor fallback used
 */
final class ShotGoalIRValidator implements ShotGoalStageValidator
{
    private const MAX_DURATION_SEC = 10.0;
    private const MIN_DURATION_SEC = 1.0;
    private const PASS             = 'ShotGoalIRValidator';

    public function validate(ShotGoalIR $ir, DiagnosticBag $bag): void
    {
        if ($ir->durationSec <= 0) {
            $bag->error(
                'Duration must be positive',
                DiagnosticCode::DURATION_ZERO,
                self::PASS, 'durationSec'
            );
        } elseif ($ir->durationSec < self::MIN_DURATION_SEC) {
            $bag->warn(
                "Duration {$ir->durationSec}s is below minimum (" . self::MIN_DURATION_SEC . "s)",
                DiagnosticCode::DURATION_BELOW_MINIMUM,
                self::PASS, 'durationSec'
            );
        } elseif ($ir->durationSec > self::MAX_DURATION_SEC) {
            $bag->warn(
                "Duration {$ir->durationSec}s exceeds Kling limit (" . self::MAX_DURATION_SEC . "s) — will be clamped",
                DiagnosticCode::DURATION_EXCEEDS_LIMIT,
                self::PASS, 'durationSec'
            );
        }

        if ($ir->energy < 0.0 || $ir->energy > 1.0) {
            $bag->warn(
                "Energy {$ir->energy} is outside valid range [0.0, 1.0]",
                DiagnosticCode::ENERGY_OUT_OF_RANGE,
                self::PASS, 'energy'
            );
        }

        if (empty($ir->goalTarget)) {
            $bag->hint(
                'goalTarget is empty — EntityExtractor fallback used, entity type may be generic',
                DiagnosticCode::GOAL_TARGET_EMPTY,
                self::PASS, 'goalTarget'
            );
        }
    }
}
