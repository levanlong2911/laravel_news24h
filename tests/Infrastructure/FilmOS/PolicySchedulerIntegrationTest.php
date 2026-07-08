<?php

declare(strict_types=1);

namespace Tests\Infrastructure\FilmOS;

use App\Services\AI\FilmOS\Capability\CapabilityDescriptor;
use App\Services\AI\FilmOS\Capability\CapabilityRegistry;
use App\Services\AI\FilmOS\Capability\CapabilityType;
use App\Services\AI\FilmOS\Meaning\CinematicFunction;
use App\Services\AI\FilmOS\Meaning\MeaningGraph;
use App\Services\AI\FilmOS\Planning\FilmPlanner;
use App\Services\AI\FilmOS\Planning\GoalEdge;
use App\Services\AI\FilmOS\Planning\GoalGraph;
use App\Services\AI\FilmOS\Planning\GoalNode;
use App\Services\AI\FilmOS\Planning\GoalNodeType;
use App\Services\AI\FilmOS\Planning\GoalRelation;
use App\Services\AI\FilmOS\Planning\PlannedShot;
use App\Services\AI\FilmOS\Planning\PlanObjectives;
use App\Services\AI\FilmOS\Planning\PolicyIntegration\PolicyAwarePlanner;
use App\Services\AI\FilmOS\Planning\PolicyIntegration\PolicyToPlanAdapter;
use App\Services\AI\FilmOS\Planning\ShotSequencePlan;
use App\Services\AI\FilmOS\Policy\Actions\DeferExecutionAction;
use App\Services\AI\FilmOS\Policy\Actions\DisableProviderAction;
use App\Services\AI\FilmOS\Policy\Actions\SetPreferredProviderAction;
use App\Services\AI\FilmOS\Policy\Conditions\AlwaysCondition;
use App\Services\AI\FilmOS\Policy\Conditions\AttributeCondition;
use App\Services\AI\FilmOS\Policy\Policy;
use App\Services\AI\FilmOS\Policy\PolicyDecision;
use App\Services\AI\FilmOS\Policy\PolicyEngine;
use App\Services\AI\FilmOS\Scheduler\ResourceScheduler;
use PHPUnit\Framework\TestCase;

/**
 * Proves that Scheduler reads the PolicyDecision that PolicyEngine produced upstream —
 * nobody re-queries PolicyEngine. PolicyDecision is the single source of truth.
 *
 * Two test categories:
 *
 *   A) scheduleWithPolicy() unit tests — Scheduler behaviour given a PolicyDecision
 *   B) Pipeline integration — PolicyDecision flows from PolicyAwarePlanner → ShotSequencePlan → Scheduler
 */
final class PolicySchedulerIntegrationTest extends TestCase
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    private function registry(array $providers): CapabilityRegistry
    {
        $reg = new CapabilityRegistry();
        foreach ($providers as [$name, $priority, $quota]) {
            $reg->register(new CapabilityDescriptor(
                providerName:   $name,
                capability:     CapabilityType::IMAGE_TO_VIDEO,
                priority:       $priority,
                costPerCallUsd: 0.01,
                dailyQuota:     $quota,
            ));
        }
        return $reg;
    }

    private function decision(array $overrides = []): PolicyDecision
    {
        $d = new PolicyDecision();
        foreach ($overrides as $prop => $value) {
            $d->$prop = $value;
        }
        return $d;
    }

    private function minimalGoalGraph(): GoalGraph
    {
        $g = new GoalGraph('root');
        $g->addNode(new GoalNode('root', 'root goal', GoalNodeType::ROOT, 1.0));
        $g->addNode(new GoalNode('leaf1', 'shot', GoalNodeType::LEAF, 0.9, 2));
        $g->addEdge(new GoalEdge('root', 'leaf1', GoalRelation::REQUIRES));
        return $g;
    }

    private function minimalMeaningGraph(): MeaningGraph
    {
        return new MeaningGraph('mg_root', CinematicFunction::ESTABLISH, 0.5, 0.9);
    }

    // ── A) scheduleWithPolicy unit tests ─────────────────────────────────────

    public function test_defer_execution_blocks_all_scheduling(): void
    {
        $scheduler = new ResourceScheduler($this->registry([['kling', 100, PHP_INT_MAX]]));
        $result    = $scheduler->scheduleWithPolicy(
            CapabilityType::IMAGE_TO_VIDEO,
            $this->decision(['deferExecution' => true]),
        );

        $this->assertNull($result, 'deferExecution must block scheduling regardless of quota');
    }

    public function test_disabled_provider_is_skipped(): void
    {
        $scheduler = new ResourceScheduler($this->registry([
            ['kling',  100, PHP_INT_MAX],
            ['runway',  80, PHP_INT_MAX],
        ]));

        $result = $scheduler->scheduleWithPolicy(
            CapabilityType::IMAGE_TO_VIDEO,
            $this->decision(['disabledProviders' => ['kling']]),
        );

        $this->assertNotNull($result);
        $this->assertSame('runway', $result->provider,
            'Disabled provider must be skipped; next priority provider wins');
    }

    public function test_all_providers_disabled_returns_null(): void
    {
        $scheduler = new ResourceScheduler($this->registry([
            ['kling',  100, PHP_INT_MAX],
            ['runway',  80, PHP_INT_MAX],
        ]));

        $result = $scheduler->scheduleWithPolicy(
            CapabilityType::IMAGE_TO_VIDEO,
            $this->decision(['disabledProviders' => ['kling', 'runway']]),
        );

        $this->assertNull($result, 'All providers disabled must yield null');
    }

    public function test_preferred_provider_promoted_above_priority_order(): void
    {
        // sora is lowest priority but the policy prefers it
        $scheduler = new ResourceScheduler($this->registry([
            ['kling',  100, PHP_INT_MAX],
            ['runway',  80, PHP_INT_MAX],
            ['sora',    60, PHP_INT_MAX],
        ]));

        $result = $scheduler->scheduleWithPolicy(
            CapabilityType::IMAGE_TO_VIDEO,
            $this->decision(['preferredProvider' => 'sora']),
        );

        $this->assertNotNull($result);
        $this->assertSame('sora', $result->provider,
            'Preferred provider must win even if it has lower registry priority');
    }

    public function test_preferred_provider_exhausted_falls_back_to_next(): void
    {
        $scheduler = new ResourceScheduler($this->registry([
            ['kling',  100, 5],    // preferred but will be exhausted
            ['runway',  80, PHP_INT_MAX],
        ]));

        // Exhaust kling's quota
        for ($i = 0; $i < 5; $i++) {
            $scheduler->recordUsage('kling');
        }

        $result = $scheduler->scheduleWithPolicy(
            CapabilityType::IMAGE_TO_VIDEO,
            $this->decision(['preferredProvider' => 'kling']),
        );

        $this->assertNotNull($result);
        $this->assertSame('runway', $result->provider,
            'When preferred provider is exhausted, must fall back to next available');
    }

    public function test_no_policy_constraints_uses_priority_waterfall(): void
    {
        $scheduler = new ResourceScheduler($this->registry([
            ['runway',  80, PHP_INT_MAX],
            ['kling',  100, PHP_INT_MAX],
            ['sora',    60, PHP_INT_MAX],
        ]));

        $result = $scheduler->scheduleWithPolicy(
            CapabilityType::IMAGE_TO_VIDEO,
            $this->decision(), // no constraints
        );

        $this->assertNotNull($result);
        $this->assertSame('kling', $result->provider,
            'Without policy constraints, highest-priority provider wins');
    }

    public function test_preferred_provider_not_registered_for_capability_falls_through(): void
    {
        $scheduler = new ResourceScheduler($this->registry([
            ['kling', 100, PHP_INT_MAX],
        ]));

        // 'sora' is not registered for IMAGE_TO_VIDEO
        $result = $scheduler->scheduleWithPolicy(
            CapabilityType::IMAGE_TO_VIDEO,
            $this->decision(['preferredProvider' => 'sora']),
        );

        $this->assertNotNull($result);
        $this->assertSame('kling', $result->provider,
            'Preferred provider not registered must silently fall through to priority waterfall');
    }

    public function test_defer_takes_precedence_over_preferred_provider(): void
    {
        $scheduler = new ResourceScheduler($this->registry([
            ['kling', 100, PHP_INT_MAX],
        ]));

        $result = $scheduler->scheduleWithPolicy(
            CapabilityType::IMAGE_TO_VIDEO,
            $this->decision([
                'deferExecution'    => true,
                'preferredProvider' => 'kling',
            ]),
        );

        $this->assertNull($result, 'deferExecution must take precedence over preferredProvider');
    }

    // ── B) Pipeline: PolicyDecision flows from Planner → ShotSequencePlan → Scheduler ──

    public function test_plan_carries_policy_decision_for_scheduler(): void
    {
        $engine = new PolicyEngine();
        $engine->register(new Policy(
            'gpu_overload_prefer_sora',
            AttributeCondition::gte('gpu_cluster.temp_c', 90.0),
            new SetPreferredProviderAction('sora'),
            500,
        ));

        // Inner planner stub: returns a minimal plan (ignores MeaningGraph)
        $inner = new class() implements FilmPlanner {
            public function plan(GoalGraph $goals, MeaningGraph $m, array $ws, PlanObjectives $o): ShotSequencePlan {
                return new ShotSequencePlan('p', $goals, [
                    new PlannedShot(1, 'root', 'shot', [], 'r'),
                ], 0.9);
            }
        };

        $planner = new PolicyAwarePlanner($inner, $engine, new PolicyToPlanAdapter());

        $plan = $planner->plan(
            $this->minimalGoalGraph(),
            $this->minimalMeaningGraph(),
            ['gpu_cluster.temp_c' => 95.0],
            PlanObjectives::quality(),
        );

        // Plan must carry the PolicyDecision so downstream (Scheduler) reads it.
        $this->assertNotNull($plan->policyDecision,
            'PolicyAwarePlanner must attach PolicyDecision to the ShotSequencePlan');
        $this->assertSame('sora', $plan->policyDecision->preferredProvider,
            'PolicyDecision attached to plan must reflect applied policy');

        // Scheduler reads from the plan — no second PolicyEngine call.
        $scheduler = new ResourceScheduler($this->registry([
            ['kling', 100, PHP_INT_MAX],
            ['sora',   60, PHP_INT_MAX],
        ]));

        $decision = $plan->policyDecision;
        $result   = $scheduler->scheduleWithPolicy(CapabilityType::IMAGE_TO_VIDEO, $decision);

        $this->assertNotNull($result);
        $this->assertSame('sora', $result->provider,
            'Scheduler must honour the preferred provider from the plan\'s PolicyDecision');
    }

    public function test_plan_carries_disabled_providers_for_scheduler(): void
    {
        $engine = new PolicyEngine();
        $engine->register(new Policy(
            'budget_zero_disable_expensive',
            AttributeCondition::lte('budget_remaining_usd', 0.0),
            new DisableProviderAction(['veo', 'runway']),
            400,
        ));

        $inner = new class() implements FilmPlanner {
            public function plan(GoalGraph $g, MeaningGraph $m, array $ws, PlanObjectives $o): ShotSequencePlan {
                return new ShotSequencePlan('p', $g, [new PlannedShot(1, 'root', 's', [], 'r')], 0.9);
            }
        };

        $planner = new PolicyAwarePlanner($inner, $engine, new PolicyToPlanAdapter());

        $plan = $planner->plan(
            $this->minimalGoalGraph(),
            $this->minimalMeaningGraph(),
            ['budget_remaining_usd' => 0.0],
            PlanObjectives::quality(),
        );

        $this->assertContains('veo',    $plan->policyDecision->disabledProviders);
        $this->assertContains('runway', $plan->policyDecision->disabledProviders);

        // Scheduler reads the policy and skips disabled providers.
        $scheduler = new ResourceScheduler($this->registry([
            ['veo',    100, PHP_INT_MAX],
            ['runway',  80, PHP_INT_MAX],
            ['kling',   60, PHP_INT_MAX],   // cheapest — only one allowed
        ]));

        $result = $scheduler->scheduleWithPolicy(CapabilityType::IMAGE_TO_VIDEO, $plan->policyDecision);

        $this->assertNotNull($result);
        $this->assertSame('kling', $result->provider,
            'Scheduler must skip veo and runway (disabled by policy) and select kling');
    }

    public function test_deferred_plan_blocks_scheduler(): void
    {
        $engine = new PolicyEngine();
        $engine->register(new Policy(
            'gpu_overload_defer',
            new AlwaysCondition(),
            new DeferExecutionAction(60_000.0),
            500,
        ));

        $inner = new class() implements FilmPlanner {
            public function plan(GoalGraph $g, MeaningGraph $m, array $ws, PlanObjectives $o): ShotSequencePlan {
                return new ShotSequencePlan('p', $g, [new PlannedShot(1, 'root', 's', [], 'r')], 0.9);
            }
        };

        $planner = new PolicyAwarePlanner($inner, $engine, new PolicyToPlanAdapter());

        $plan = $planner->plan(
            $this->minimalGoalGraph(),
            $this->minimalMeaningGraph(),
            [],
            PlanObjectives::quality(),
        );

        $this->assertTrue($plan->policyDecision->deferExecution);

        $scheduler = new ResourceScheduler($this->registry([['kling', 100, PHP_INT_MAX]]));
        $result    = $scheduler->scheduleWithPolicy(CapabilityType::IMAGE_TO_VIDEO, $plan->policyDecision);

        $this->assertNull($result,
            'A deferred plan must yield null from scheduleWithPolicy — execution must not start');
    }
}
