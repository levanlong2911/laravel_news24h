<?php

namespace Tests\Unit\AFOS\Passes\Prompt;

use App\Services\AI\AFOS\Ir\PromptIR;
use App\Services\AI\AFOS\Ir\PromptPlanningInput;
use App\Services\AI\AFOS\Passes\Prompt\KlingPromptPlanningPass;
use App\Services\AI\AFOS\Passes\Prompt\PlannerRegistry;
use App\Services\AI\AFOS\Passes\Prompt\PromptPlannerInterface;
use PHPUnit\Framework\TestCase;

final class PlannerRegistryTest extends TestCase
{
    private function fakePlanner(string $backendId): PromptPlannerInterface
    {
        return new class($backendId) implements PromptPlannerInterface {
            public function __construct(private string $bid) {}
            public function backendId(): string { return $this->bid; }
            public function name(): string { return "FakePlanner[{$this->bid}]"; }
            public function plan(PromptPlanningInput $input): PromptIR { throw new \LogicException('stub'); }
        };
    }

    // ── withDefaults ──────────────────────────────────────────────────────────

    public function test_with_defaults_registers_kling(): void
    {
        $registry = PlannerRegistry::withDefaults();
        $this->assertTrue($registry->has('kling'));
        $this->assertInstanceOf(KlingPromptPlanningPass::class, $registry->planner('kling'));
    }

    public function test_with_defaults_has_kling_as_only_backend(): void
    {
        $this->assertSame(['kling'], PlannerRegistry::withDefaults()->registeredBackendIds());
    }

    // ── register ─────────────────────────────────────────────────────────────

    public function test_register_adds_new_planner(): void
    {
        $registry = PlannerRegistry::withDefaults()->register($this->fakePlanner('veo'));

        $this->assertTrue($registry->has('veo'));
        $this->assertTrue($registry->has('kling'));
    }

    public function test_register_is_immutable(): void
    {
        $original = PlannerRegistry::withDefaults();
        $extended = $original->register($this->fakePlanner('veo'));

        $this->assertFalse($original->has('veo'), 'register() must not mutate original');
        $this->assertTrue($extended->has('veo'));
    }

    public function test_register_replaces_planner_with_same_backend_id(): void
    {
        $v1 = $this->fakePlanner('kling');
        $v2 = $this->fakePlanner('kling');

        $registry = PlannerRegistry::withDefaults()->register($v2);
        $this->assertSame($v2, $registry->planner('kling'));
    }

    // ── planner ───────────────────────────────────────────────────────────────

    public function test_planner_returns_correct_implementation(): void
    {
        $veo      = $this->fakePlanner('veo');
        $registry = PlannerRegistry::withDefaults()->register($veo);

        $this->assertSame($veo, $registry->planner('veo'));
    }

    public function test_planner_throws_for_unknown_backend_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/No prompt planner registered for backend 'sora'/i");

        PlannerRegistry::withDefaults()->planner('sora');
    }

    public function test_error_message_lists_registered_backend_ids(): void
    {
        $registry = PlannerRegistry::withDefaults()->register($this->fakePlanner('veo'));

        try {
            $registry->planner('sora');
            $this->fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('kling', $e->getMessage());
            $this->assertStringContainsString('veo',   $e->getMessage());
        }
    }

    // ── KlingPromptPlanningPass implements PromptPlannerInterface ─────────────

    public function test_kling_planner_reports_kling_as_backend_id(): void
    {
        $planner = PlannerRegistry::withDefaults()->planner('kling');
        $this->assertSame('kling', $planner->backendId());
    }

    public function test_kling_planner_has_name(): void
    {
        $planner = PlannerRegistry::withDefaults()->planner('kling');
        $this->assertNotEmpty($planner->name());
    }
}
