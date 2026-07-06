<?php

namespace Tests\Unit\AFOS\Passes;

use App\Services\AI\AFOS\Passes\Pipeline\CompilerPhase;
use PHPUnit\Framework\TestCase;

final class CompilerPhaseTest extends TestCase
{
    // ── isBefore ─────────────────────────────────────────────────────────────

    public function test_build_is_before_freeze(): void
    {
        $this->assertTrue(CompilerPhase::BUILD->isBefore(CompilerPhase::FREEZE));
    }

    public function test_freeze_is_before_lower(): void
    {
        $this->assertTrue(CompilerPhase::FREEZE->isBefore(CompilerPhase::LOWER));
    }

    public function test_lower_is_before_emit(): void
    {
        $this->assertTrue(CompilerPhase::LOWER->isBefore(CompilerPhase::EMIT));
    }

    public function test_same_phase_is_not_before_itself(): void
    {
        $this->assertFalse(CompilerPhase::BUILD->isBefore(CompilerPhase::BUILD));
    }

    // ── isAfter ──────────────────────────────────────────────────────────────

    public function test_lower_is_after_freeze(): void
    {
        $this->assertTrue(CompilerPhase::LOWER->isAfter(CompilerPhase::FREEZE));
    }

    public function test_emit_is_after_build(): void
    {
        $this->assertTrue(CompilerPhase::EMIT->isAfter(CompilerPhase::BUILD));
    }

    public function test_isBefore_and_isAfter_are_strict_inverses(): void
    {
        $phases = CompilerPhase::cases();
        foreach ($phases as $a) {
            foreach ($phases as $b) {
                if ($a->isBefore($b)) {
                    $this->assertTrue($b->isAfter($a), "{$b->value} must be after {$a->value}");
                }
                if ($a->isAfter($b)) {
                    $this->assertTrue($b->isBefore($a), "{$b->value} must be before {$a->value}");
                }
            }
        }
    }

    // ── canDependOn ──────────────────────────────────────────────────────────

    public function test_build_can_depend_on_build(): void
    {
        $this->assertTrue(CompilerPhase::BUILD->canDependOn(CompilerPhase::BUILD));
    }

    public function test_build_cannot_depend_on_lower(): void
    {
        $this->assertFalse(CompilerPhase::BUILD->canDependOn(CompilerPhase::LOWER));
    }

    public function test_build_cannot_depend_on_emit(): void
    {
        $this->assertFalse(CompilerPhase::BUILD->canDependOn(CompilerPhase::EMIT));
    }

    public function test_lower_can_depend_on_freeze(): void
    {
        $this->assertTrue(CompilerPhase::LOWER->canDependOn(CompilerPhase::FREEZE));
    }

    public function test_lower_can_depend_on_build(): void
    {
        $this->assertTrue(CompilerPhase::LOWER->canDependOn(CompilerPhase::BUILD));
    }

    public function test_emit_can_depend_on_lower(): void
    {
        $this->assertTrue(CompilerPhase::EMIT->canDependOn(CompilerPhase::LOWER));
    }

    public function test_validate_can_depend_on_any_phase(): void
    {
        foreach (CompilerPhase::cases() as $producer) {
            $this->assertTrue(
                CompilerPhase::VALIDATE->canDependOn($producer),
                "VALIDATE must be able to depend on {$producer->value}"
            );
        }
    }

    public function test_can_depend_on_is_reflexive(): void
    {
        foreach (CompilerPhase::cases() as $phase) {
            $this->assertTrue(
                $phase->canDependOn($phase),
                "{$phase->value} must be able to depend on itself"
            );
        }
    }

    // ── transitionTo ─────────────────────────────────────────────────────────

    public function test_build_transitions_to_freeze(): void
    {
        $next = CompilerPhase::BUILD->transitionTo(CompilerPhase::FREEZE);
        $this->assertSame(CompilerPhase::FREEZE, $next);
    }

    public function test_freeze_transitions_to_lower(): void
    {
        $next = CompilerPhase::FREEZE->transitionTo(CompilerPhase::LOWER);
        $this->assertSame(CompilerPhase::LOWER, $next);
    }

    public function test_freeze_transitions_to_optimize(): void
    {
        $next = CompilerPhase::FREEZE->transitionTo(CompilerPhase::OPTIMIZE);
        $this->assertSame(CompilerPhase::OPTIMIZE, $next);
    }

    public function test_optimize_transitions_to_lower(): void
    {
        $next = CompilerPhase::OPTIMIZE->transitionTo(CompilerPhase::LOWER);
        $this->assertSame(CompilerPhase::LOWER, $next);
    }

    public function test_lower_transitions_to_emit(): void
    {
        $next = CompilerPhase::LOWER->transitionTo(CompilerPhase::EMIT);
        $this->assertSame(CompilerPhase::EMIT, $next);
    }

    public function test_build_cannot_skip_to_lower(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/build.*lower/i');
        CompilerPhase::BUILD->transitionTo(CompilerPhase::LOWER);
    }

    public function test_freeze_cannot_skip_to_emit(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/freeze.*emit/i');
        CompilerPhase::FREEZE->transitionTo(CompilerPhase::EMIT);
    }

    public function test_emit_is_terminal(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/emit/i');
        CompilerPhase::EMIT->transitionTo(CompilerPhase::VALIDATE);
    }

    public function test_validate_has_no_transitions(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/validate/i');
        CompilerPhase::VALIDATE->transitionTo(CompilerPhase::EMIT);
    }
}
