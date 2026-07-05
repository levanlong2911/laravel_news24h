<?php

namespace Tests\Unit\AFOS\Compiler\Validators;

use App\Services\AI\AFOS\Compiler\Diagnostics\DiagnosticBag;
use App\Services\AI\AFOS\Compiler\Diagnostics\DiagnosticCode;
use App\Services\AI\AFOS\Compiler\Validators\ShotGoalIRValidator;
use App\Services\AI\AFOS\Ir\ShotGoalIR;
use PHPUnit\Framework\TestCase;

/**
 * ShotGoalIRValidatorPropertyTest — QuickCheck-style property tests.
 *
 * Rather than testing specific example values, these tests generate large
 * random input spaces and assert that invariants hold for all of them:
 *   - The validator never throws (total function)
 *   - The validator is deterministic (same input → same output)
 *   - The correct diagnostic codes fire at the right boundaries
 *
 * All pseudo-random generation is seeded so failures are reproducible.
 */
class ShotGoalIRValidatorPropertyTest extends TestCase
{
    private const ITERATIONS = 1_000;
    private const SEED       = 42;

    private ShotGoalIRValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ShotGoalIRValidator();
        mt_srand(self::SEED);
    }

    private function makeShot(array $overrides = []): ShotGoalIR
    {
        return ShotGoalIR::fromArray(array_merge([
            'shotId'             => 'prop-shot',
            'durationSec'        => 5.0,
            'goalType'           => 'reveal',
            'goalTarget'         => 'hull',
            'viewerShouldNotice' => ['texture'],
            'viewerShouldIgnore' => [],
            'emotion'            => 'serenity',
            'energy'             => 0.5,
            'narrativeFunction'  => 'establish',
        ], $overrides));
    }

    // ── Property 1: total function (never throws) ──────────────────────────────

    public function test_validator_never_throws_for_any_duration(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $duration = (mt_rand(-100_000, 100_000)) / 100.0; // -1000.0 to +1000.0
            $bag      = new DiagnosticBag();

            try {
                $this->validator->validate($this->makeShot(['durationSec' => $duration]), $bag);
                $this->assertTrue(true); // no exception
            } catch (\Throwable $e) {
                $this->fail("Validator threw at durationSec={$duration}: " . get_class($e) . ': ' . $e->getMessage());
            }
        }
    }

    public function test_validator_never_throws_for_any_energy(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $energy = (mt_rand(-500, 500)) / 100.0; // -5.0 to +5.0
            $bag    = new DiagnosticBag();

            try {
                $this->validator->validate($this->makeShot(['energy' => $energy]), $bag);
                $this->assertTrue(true);
            } catch (\Throwable $e) {
                $this->fail("Validator threw at energy={$energy}: " . get_class($e) . ': ' . $e->getMessage());
            }
        }
    }

    // ── Property 2: deterministic (same input → identical output) ─────────────

    public function test_validator_is_deterministic_over_duration_range(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $duration = (mt_rand(-50_000, 50_000)) / 100.0;
            $shot     = $this->makeShot(['durationSec' => $duration]);

            $bag1 = new DiagnosticBag();
            $bag2 = new DiagnosticBag();
            $this->validator->validate($shot, $bag1);
            $this->validator->validate($shot, $bag2);

            $this->assertSame(
                $bag1->toArray(),
                $bag2->toArray(),
                "Non-deterministic at durationSec={$duration}"
            );
        }
    }

    public function test_validator_is_deterministic_over_energy_range(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $energy = (mt_rand(-300, 300)) / 100.0;
            $shot   = $this->makeShot(['energy' => $energy]);

            $bag1 = new DiagnosticBag();
            $bag2 = new DiagnosticBag();
            $this->validator->validate($shot, $bag1);
            $this->validator->validate($shot, $bag2);

            $this->assertSame($bag1->toArray(), $bag2->toArray(), "Non-deterministic at energy={$energy}");
        }
    }

    // ── Property 3: boundary monotonicity ─────────────────────────────────────

    public function test_error_code_afos1001_fires_iff_duration_lte_zero(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $duration  = (mt_rand(-100_000, 100_000)) / 100.0;
            $bag       = new DiagnosticBag();
            $this->validator->validate($this->makeShot(['durationSec' => $duration]), $bag);

            $errorCodes = array_map(fn($d) => $d->code, $bag->errors());
            $hasCode    = in_array(DiagnosticCode::DURATION_ZERO, $errorCodes, true);

            if ($duration <= 0) {
                $this->assertTrue($hasCode, "AFOS1001 should fire when durationSec={$duration} <= 0");
            } else {
                $this->assertFalse($hasCode, "AFOS1001 must NOT fire when durationSec={$duration} > 0");
            }
        }
    }

    public function test_warning_code_afos1004_fires_iff_energy_out_of_range(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $energy  = (mt_rand(-300, 300)) / 100.0;
            $bag     = new DiagnosticBag();
            $this->validator->validate($this->makeShot(['energy' => $energy]), $bag);

            $warnCodes = array_map(fn($d) => $d->code, $bag->warnings());
            $hasCode   = in_array(DiagnosticCode::ENERGY_OUT_OF_RANGE, $warnCodes, true);

            $outOfRange = $energy < 0.0 || $energy > 1.0;
            if ($outOfRange) {
                $this->assertTrue($hasCode, "AFOS1004 should fire when energy={$energy} is outside [0,1]");
            } else {
                $this->assertFalse($hasCode, "AFOS1004 must NOT fire when energy={$energy} is within [0,1]");
            }
        }
    }

    // ── Property 4: diagnostic count is bounded ───────────────────────────────

    public function test_diagnostic_count_never_exceeds_maximum(): void
    {
        // Worst case: duration ≤ 0 (1 error) + energy OOB (1 warn) + empty goalTarget (1 hint)
        // Maximum expected = 3 diagnostics total.
        $maxExpected = 3;

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $duration = (mt_rand(-100_000, 100_000)) / 100.0;
            $energy   = (mt_rand(-300, 300)) / 100.0;
            $target   = mt_rand(0, 3) === 0 ? '' : 'hull'; // 25% chance of empty target

            $bag = new DiagnosticBag();
            $this->validator->validate(
                $this->makeShot(['durationSec' => $duration, 'energy' => $energy, 'goalTarget' => $target]),
                $bag
            );

            $count = count($bag->all());
            $this->assertLessThanOrEqual(
                $maxExpected,
                $count,
                "Diagnostic count {$count} exceeded max {$maxExpected} at duration={$duration} energy={$energy}"
            );
        }
    }

    // ── Property 5: no diagnostic is emitted for valid inputs ─────────────────

    public function test_valid_inputs_produce_no_diagnostics(): void
    {
        $validDurations = [1.0, 2.5, 5.0, 7.0, 10.0];
        $validEnergies  = [0.0, 0.25, 0.5, 0.75, 1.0];

        foreach ($validDurations as $duration) {
            foreach ($validEnergies as $energy) {
                $bag = new DiagnosticBag();
                $this->validator->validate(
                    $this->makeShot(['durationSec' => $duration, 'energy' => $energy, 'goalTarget' => 'hull']),
                    $bag
                );
                $this->assertEmpty(
                    $bag->all(),
                    "Expected zero diagnostics for valid input duration={$duration} energy={$energy}"
                );
            }
        }
    }
}
