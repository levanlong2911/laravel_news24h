<?php

namespace Tests\Unit\AFOS\Compiler\Validators;

use App\Services\AI\AFOS\Compiler\Diagnostics\DiagnosticBag;
use App\Services\AI\AFOS\Compiler\Diagnostics\DiagnosticCode;
use App\Services\AI\AFOS\Compiler\Validators\ShotGoalIRValidator;
use App\Services\AI\AFOS\Ir\ShotGoalIR;
use PHPUnit\Framework\TestCase;

class ShotGoalIRValidatorTest extends TestCase
{
    private ShotGoalIRValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ShotGoalIRValidator();
    }

    private function makeShot(array $overrides = []): ShotGoalIR
    {
        return ShotGoalIR::fromArray(array_merge([
            'shotId'             => 'shot-001',
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

    public function test_valid_shot_emits_no_diagnostics(): void
    {
        $bag = new DiagnosticBag();
        $this->validator->validate($this->makeShot(), $bag);

        $this->assertFalse($bag->hasErrors());
        $this->assertEmpty($bag->warnings());
        $this->assertEmpty($bag->hints());
    }

    public function test_duration_zero_emits_error_afos1001(): void
    {
        $bag = new DiagnosticBag();
        $this->validator->validate($this->makeShot(['durationSec' => 0.0]), $bag);

        $this->assertTrue($bag->hasErrors());
        $errors = $bag->errors();
        $this->assertCount(1, $errors);
        $this->assertSame(DiagnosticCode::DURATION_ZERO, $errors[0]->code);
        $this->assertSame('durationSec', $errors[0]->field);
    }

    public function test_negative_duration_also_emits_error_afos1001(): void
    {
        $bag = new DiagnosticBag();
        $this->validator->validate($this->makeShot(['durationSec' => -1.5]), $bag);

        $this->assertTrue($bag->hasErrors());
        $this->assertSame(DiagnosticCode::DURATION_ZERO, $bag->errors()[0]->code);
    }

    public function test_duration_below_minimum_emits_warning_afos1002(): void
    {
        $bag = new DiagnosticBag();
        $this->validator->validate($this->makeShot(['durationSec' => 0.5]), $bag);

        $this->assertFalse($bag->hasErrors());
        $warnings = $bag->warnings();
        $this->assertCount(1, $warnings);
        $this->assertSame(DiagnosticCode::DURATION_BELOW_MINIMUM, $warnings[0]->code);
    }

    public function test_duration_exceeds_limit_emits_warning_afos1003(): void
    {
        $bag = new DiagnosticBag();
        $this->validator->validate($this->makeShot(['durationSec' => 12.0]), $bag);

        $this->assertFalse($bag->hasErrors());
        $warnings = $bag->warnings();
        $this->assertCount(1, $warnings);
        $this->assertSame(DiagnosticCode::DURATION_EXCEEDS_LIMIT, $warnings[0]->code);
    }

    public function test_energy_above_range_emits_warning_afos1004(): void
    {
        $bag = new DiagnosticBag();
        $this->validator->validate($this->makeShot(['energy' => 1.5]), $bag);

        $this->assertFalse($bag->hasErrors());
        $this->assertSame(DiagnosticCode::ENERGY_OUT_OF_RANGE, $bag->warnings()[0]->code);
        $this->assertSame('energy', $bag->warnings()[0]->field);
    }

    public function test_energy_below_range_emits_warning_afos1004(): void
    {
        $bag = new DiagnosticBag();
        $this->validator->validate($this->makeShot(['energy' => -0.1]), $bag);

        $this->assertSame(DiagnosticCode::ENERGY_OUT_OF_RANGE, $bag->warnings()[0]->code);
    }

    public function test_empty_goal_target_emits_hint_afos1005(): void
    {
        $bag = new DiagnosticBag();
        $this->validator->validate($this->makeShot(['goalTarget' => '']), $bag);

        $this->assertFalse($bag->hasErrors());
        $hints = $bag->hints();
        $this->assertCount(1, $hints);
        $this->assertSame(DiagnosticCode::GOAL_TARGET_EMPTY, $hints[0]->code);
    }

    public function test_multiple_issues_accumulate_independently(): void
    {
        $bag = new DiagnosticBag();
        $this->validator->validate($this->makeShot([
            'durationSec' => 15.0,  // AFOS1003 warning
            'energy'      => 1.2,   // AFOS1004 warning
            'goalTarget'  => '',    // AFOS1005 hint
        ]), $bag);

        $this->assertFalse($bag->hasErrors());
        $this->assertCount(2, $bag->warnings());
        $this->assertCount(1, $bag->hints());
    }

    public function test_boundary_duration_at_minimum_is_valid(): void
    {
        $bag = new DiagnosticBag();
        $this->validator->validate($this->makeShot(['durationSec' => 1.0]), $bag);

        $this->assertEmpty($bag->warnings());
        $this->assertFalse($bag->hasErrors());
    }

    public function test_boundary_duration_at_maximum_is_valid(): void
    {
        $bag = new DiagnosticBag();
        $this->validator->validate($this->makeShot(['durationSec' => 10.0]), $bag);

        $this->assertEmpty($bag->warnings());
        $this->assertFalse($bag->hasErrors());
    }

    public function test_pass_name_is_included_in_diagnostic(): void
    {
        $bag = new DiagnosticBag();
        $this->validator->validate($this->makeShot(['durationSec' => 0.0]), $bag);

        $this->assertSame('ShotGoalIRValidator', $bag->errors()[0]->pass);
    }
}
