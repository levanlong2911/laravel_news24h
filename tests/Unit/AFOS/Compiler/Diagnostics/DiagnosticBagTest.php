<?php

namespace Tests\Unit\AFOS\Compiler\Diagnostics;

use App\Services\AI\AFOS\Compiler\Diagnostics\DiagnosticBag;
use App\Services\AI\AFOS\Compiler\Diagnostics\DiagnosticCode;
use PHPUnit\Framework\TestCase;

class DiagnosticBagTest extends TestCase
{
    public function test_empty_bag_has_no_errors(): void
    {
        $bag = new DiagnosticBag();

        $this->assertFalse($bag->hasErrors());
        $this->assertTrue($bag->isEmpty());
        $this->assertEmpty($bag->all());
    }

    public function test_error_sets_has_errors(): void
    {
        $bag = new DiagnosticBag();
        $bag->error('something broken');

        $this->assertTrue($bag->hasErrors());
        $this->assertFalse($bag->isEmpty());
    }

    public function test_warn_does_not_set_has_errors(): void
    {
        $bag = new DiagnosticBag();
        $bag->warn('mild issue');

        $this->assertFalse($bag->hasErrors());
        $this->assertCount(1, $bag->warnings());
    }

    public function test_hint_does_not_set_has_errors(): void
    {
        $bag = new DiagnosticBag();
        $bag->hint('fyi');

        $this->assertFalse($bag->hasErrors());
        $this->assertCount(1, $bag->hints());
    }

    public function test_severity_filters_are_mutually_exclusive(): void
    {
        $bag = new DiagnosticBag();
        $bag->error('e');
        $bag->warn('w');
        $bag->hint('h');

        $this->assertCount(1, $bag->errors());
        $this->assertCount(1, $bag->warnings());
        $this->assertCount(1, $bag->hints());
        $this->assertCount(3, $bag->all());
    }

    public function test_diagnostic_code_is_stored(): void
    {
        $bag = new DiagnosticBag();
        $bag->error('zero', DiagnosticCode::DURATION_ZERO, 'Validator', 'durationSec');

        $d = $bag->errors()[0];
        $this->assertSame(DiagnosticCode::DURATION_ZERO, $d->code);
        $this->assertSame('Validator', $d->pass);
        $this->assertSame('durationSec', $d->field);
    }

    public function test_format_includes_code_and_location(): void
    {
        $bag = new DiagnosticBag();
        $bag->error('zero', DiagnosticCode::DURATION_ZERO, 'MyPass', 'dur');

        $formatted = $bag->format();
        $this->assertStringContainsString('AFOS1001', $formatted);
        $this->assertStringContainsString('[MyPass]', $formatted);
        $this->assertStringContainsString('.dur', $formatted);
        $this->assertStringContainsString('zero', $formatted);
    }

    public function test_format_without_code_uses_compiler_location(): void
    {
        $bag = new DiagnosticBag();
        $bag->warn('generic warning');

        $this->assertStringContainsString('[compiler]', $bag->format());
    }

    public function test_merge_combines_diagnostics(): void
    {
        $bag1 = new DiagnosticBag();
        $bag1->error('e1');

        $bag2 = new DiagnosticBag();
        $bag2->warn('w1');
        $bag2->hint('h1');

        $bag1->merge($bag2);

        $this->assertCount(3, $bag1->all());
        $this->assertTrue($bag1->hasErrors());
        $this->assertCount(1, $bag1->warnings());
    }

    public function test_merge_does_not_mutate_source(): void
    {
        $bag1 = new DiagnosticBag();
        $bag1->error('e');

        $bag2 = new DiagnosticBag();
        $bag1->merge($bag2);

        $this->assertCount(1, $bag1->all());
    }

    public function test_to_array_includes_all_fields(): void
    {
        $bag = new DiagnosticBag();
        $bag->error('msg', DiagnosticCode::DURATION_ZERO, 'Pass', 'field');

        $arr = $bag->toArray();
        $this->assertCount(1, $arr);
        $this->assertSame('error', $arr[0]['severity']);
        $this->assertSame('AFOS1001', $arr[0]['code']);
        $this->assertSame('msg', $arr[0]['message']);
        $this->assertSame('Pass', $arr[0]['pass']);
        $this->assertSame('field', $arr[0]['field']);
    }

    public function test_to_array_omits_null_fields(): void
    {
        $bag = new DiagnosticBag();
        $bag->warn('no code no pass');

        $arr = $bag->toArray()[0];
        $this->assertArrayNotHasKey('code', $arr);
        $this->assertArrayNotHasKey('pass', $arr);
        $this->assertArrayNotHasKey('field', $arr);
    }
}
