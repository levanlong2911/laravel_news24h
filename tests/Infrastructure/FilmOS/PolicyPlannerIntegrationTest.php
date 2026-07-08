<?php

declare(strict_types=1);

namespace Tests\Infrastructure\FilmOS;

use App\Services\AI\FilmOS\EventBus\EventBus;
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
use App\Services\AI\FilmOS\Planning\PolicyIntegration\PlanningDecisionEvent;
use App\Services\AI\FilmOS\Planning\PolicyIntegration\PolicyAwarePlanner;
use App\Services\AI\FilmOS\Planning\PolicyIntegration\PolicyToPlanAdapter;
use App\Services\AI\FilmOS\Planning\ShotSequencePlan;
use App\Services\AI\FilmOS\Policy\Actions\DeferExecutionAction;
use App\Services\AI\FilmOS\Policy\Actions\RequireReviewersAction;
use App\Services\AI\FilmOS\Policy\Actions\SetMaxLatencyAction;
use App\Services\AI\FilmOS\Policy\Actions\SetPreferredProviderAction;
use App\Services\AI\FilmOS\Policy\Actions\SetQualityBiasAction;
use App\Services\AI\FilmOS\Policy\Actions\DisableProviderAction;
use App\Services\AI\FilmOS\Policy\Conditions\AlwaysCondition;
use App\Services\AI\FilmOS\Policy\Conditions\AttributeCondition;
use App\Services\AI\FilmOS\Policy\Policy;
use App\Services\AI\FilmOS\Policy\PolicyEngine;
use PHPUnit\Framework\TestCase;

/**
 * Proves that PolicyEngine is wired INTO the planning loop — not beside it.
 *
 * Every test follows the same production pattern:
 *   worldState (context) → PolicyEngine → constrained PlanObjectives → Plan
 *
 * The four named scenarios from the architecture evaluation:
 *   1. Breaking News  → latency constrained to ≤ 10s
 *   2. Premium        → quality bias applied to objective weights
 *   3. Budget zero    → cost bias, planner gets cost-optimised objectives
 *   4. Low confidence → minReviewScore raised by 5pp per extra reviewer
 */
final class PolicyPlannerIntegrationTest extends TestCase
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    private function stubPlan(GoalGraph $goals): ShotSequencePlan
    {
        return new ShotSequencePlan(
            planId:         'test-plan',
            goalGraph:      $goals,
            shots:          [
                new PlannedShot(1, 'g1', 'Opening shot', ['visual' => 'wide'], 'test rationale'),
            ],
            goalConfidence: 0.90,
        );
    }

    /**
     * Returns a FilmPlanner stub whose public `captured` property holds the PlanObjectives
     * it was last called with. `__get` proxies property reads to the captured object so
     * test assertions (`$captured->maxLatencyMs`) work without extra indirection.
     *
     * We use an object — not a PHP reference — because array destructuring copies reference
     * values: `[$planner, $captured] = buildPlanner()` severs the reference chain and
     * leaves $captured permanently null.
     */
    private function capturingPlanner(): FilmPlanner
    {
        return new class() implements FilmPlanner {
            public ?PlanObjectives $captured = null;

            public function __get(string $name): mixed
            {
                return $this->captured?->$name;
            }

            public function plan(
                GoalGraph      $goals,
                MeaningGraph   $meaning,
                array          $worldState,
                PlanObjectives $objectives,
            ): ShotSequencePlan {
                $this->captured = $objectives;
                return new ShotSequencePlan('p', $goals, [
                    new PlannedShot(1, 'g1', 'shot', [], 'r'),
                ], 0.85);
            }
        };
    }

    private function minimalMeaningGraph(): MeaningGraph
    {
        return new MeaningGraph('mg_root', CinematicFunction::ESTABLISH, 0.5, 0.9);
    }

    private function minimalGoalGraph(): GoalGraph
    {
        $g = new GoalGraph('root');
        $g->addNode(new GoalNode('root', 'root goal', GoalNodeType::ROOT, 1.0));
        $g->addNode(new GoalNode('leaf1', 'render hook', GoalNodeType::LEAF, 0.9, 2));
        $g->addEdge(new GoalEdge('root', 'leaf1', GoalRelation::REQUIRES));
        return $g;
    }

    /** Returns [$planner, $inner] where $inner is the capturing stub (object — not a reference). */
    private function buildPlanner(PolicyEngine $engine, ?EventBus $bus = null): array
    {
        $inner   = $this->capturingPlanner();
        $adapter = new PolicyToPlanAdapter();
        $planner = new PolicyAwarePlanner($inner, $engine, $adapter, $bus);
        return [$planner, $inner];
    }

    // ── Scenario 1: Breaking News → latency ≤ 10s ────────────────────────────

    public function test_breaking_news_constrains_max_latency_to_10s(): void
    {
        $engine = new PolicyEngine();
        $engine->register(new Policy(
            'breaking_news_latency',
            AttributeCondition::eq('content_type', 'breaking_news'),
            new SetMaxLatencyAction(10_000.0),
            200,
        ));

        [$planner, $captured] = $this->buildPlanner($engine);

        $base = PlanObjectives::quality(); // maxLatencyMs = 60 000

        $planner->plan(
            $this->minimalGoalGraph(),
            $this->minimalMeaningGraph(),
            ['content_type' => 'breaking_news'],
            $base,
        );

        $this->assertNotNull($captured);
        $this->assertSame(10_000, $captured->maxLatencyMs,
            'PolicyEngine must constrain maxLatencyMs to 10 000ms for breaking news');
        $this->assertLessThan($base->maxLatencyMs, $captured->maxLatencyMs,
            'Constrained latency must be stricter than the base quality preset');
    }

    public function test_non_breaking_news_preserves_base_latency(): void
    {
        $engine = new PolicyEngine();
        $engine->register(new Policy(
            'breaking_news_latency',
            AttributeCondition::eq('content_type', 'breaking_news'),
            new SetMaxLatencyAction(10_000.0),
            200,
        ));

        [$planner, $captured] = $this->buildPlanner($engine);
        $base = PlanObjectives::quality();

        $planner->plan(
            $this->minimalGoalGraph(),
            $this->minimalMeaningGraph(),
            ['content_type' => 'documentary'],
            $base,
        );

        $this->assertSame($base->maxLatencyMs, $captured->maxLatencyMs,
            'Non-breaking-news must not have its latency constrained');
    }

    // ── Scenario 2: Premium → quality bias ───────────────────────────────────

    public function test_premium_customer_shifts_weights_to_quality_bias(): void
    {
        $engine = new PolicyEngine();
        $engine->register(new Policy(
            'premium_quality',
            AttributeCondition::eq('customer_tier', 'premium'),
            new SetQualityBiasAction('quality'),
            300,
        ));

        [$planner, $captured] = $this->buildPlanner($engine);
        $base = PlanObjectives::breakingNews(); // was cost/latency biased

        $planner->plan(
            $this->minimalGoalGraph(),
            $this->minimalMeaningGraph(),
            ['customer_tier' => 'premium'],
            $base,
        );

        // quality bias: narrativeWeight=0.50, reviewScoreWeight=0.25, costWeight=0.10
        $this->assertSame(0.50, $captured->narrativeWeight);
        $this->assertSame(0.10, $captured->costWeight);
        $this->assertSame(0.25, $captured->reviewScoreWeight);
    }

    public function test_budget_customer_shifts_weights_to_cost_bias(): void
    {
        $engine = new PolicyEngine();
        $engine->register(new Policy(
            'budget_cost_first',
            AttributeCondition::eq('customer_tier', 'budget'),
            new SetQualityBiasAction('cost'),
            300,
        ));

        [$planner, $captured] = $this->buildPlanner($engine);

        $planner->plan(
            $this->minimalGoalGraph(),
            $this->minimalMeaningGraph(),
            ['customer_tier' => 'budget'],
            PlanObjectives::quality(),
        );

        // cost bias: costWeight=0.45, narrativeWeight=0.25
        $this->assertSame(0.45, $captured->costWeight);
        $this->assertSame(0.25, $captured->narrativeWeight);
    }

    // ── Scenario 3: Budget zero → disable expensive, cost bias ───────────────

    public function test_exhausted_budget_applies_cost_bias_to_planner(): void
    {
        $engine = new PolicyEngine();
        $engine->register(new Policy(
            'budget_exhausted',
            AttributeCondition::lte('budget_remaining_usd', 0.0),
            new SetQualityBiasAction('cost'),
            400,
        ));
        $engine->register(new Policy(
            'budget_disable_premium',
            AttributeCondition::lte('budget_remaining_usd', 0.0),
            new DisableProviderAction(['veo', 'runway']),
            400,
        ));

        [$planner, $captured] = $this->buildPlanner($engine);

        $planner->plan(
            $this->minimalGoalGraph(),
            $this->minimalMeaningGraph(),
            ['budget_remaining_usd' => 0.0],
            PlanObjectives::quality(),
        );

        $this->assertSame(0.45, $captured->costWeight,
            'Zero budget must shift planner to cost-optimisation mode');
    }

    // ── Scenario 4: Low confidence → minReviewScore raised ───────────────────

    public function test_low_confidence_raises_min_review_score(): void
    {
        $engine = new PolicyEngine();
        $engine->register(new Policy(
            'low_confidence_review',
            AttributeCondition::lt('reviewer_confidence', 0.7),
            new RequireReviewersAction(3),
            150,
        ));

        [$planner, $captured] = $this->buildPlanner($engine);
        $base = PlanObjectives::quality(); // minReviewScore = 0.80

        $planner->plan(
            $this->minimalGoalGraph(),
            $this->minimalMeaningGraph(),
            ['reviewer_confidence' => 0.55],
            $base,
        );

        // requiredReviewers=3 → +2 extra reviewers → +2 × 0.05 = 0.10
        $expected = min(0.95, 0.80 + (3 - 1) * 0.05);
        $this->assertEqualsWithDelta($expected, $captured->minReviewScore, 0.001,
            'Low confidence must raise minReviewScore by 5pp per extra reviewer');
    }

    public function test_high_confidence_does_not_change_review_score(): void
    {
        $engine = new PolicyEngine();
        $engine->register(new Policy(
            'low_confidence_review',
            AttributeCondition::lt('reviewer_confidence', 0.7),
            new RequireReviewersAction(3),
            150,
        ));

        [$planner, $captured] = $this->buildPlanner($engine);
        $base = PlanObjectives::quality();

        $planner->plan(
            $this->minimalGoalGraph(),
            $this->minimalMeaningGraph(),
            ['reviewer_confidence' => 0.95],
            $base,
        );

        $this->assertSame($base->minReviewScore, $captured->minReviewScore);
    }

    // ── All 4 policies simultaneously ────────────────────────────────────────

    public function test_all_four_policies_applied_in_single_plan(): void
    {
        $engine = new PolicyEngine();
        $engine->register(new Policy('breaking_news_latency',
            AttributeCondition::eq('content_type', 'breaking_news'),
            new SetMaxLatencyAction(10_000.0), 200,
        ));
        $engine->register(new Policy('premium_quality',
            AttributeCondition::eq('customer_tier', 'premium'),
            new SetQualityBiasAction('quality'), 300,
        ));
        $engine->register(new Policy('low_confidence_review',
            AttributeCondition::lt('reviewer_confidence', 0.7),
            new RequireReviewersAction(3), 150,
        ));

        [$planner, $captured] = $this->buildPlanner($engine);

        $planner->plan(
            $this->minimalGoalGraph(),
            $this->minimalMeaningGraph(),
            [
                'content_type'        => 'breaking_news',
                'customer_tier'       => 'premium',
                'reviewer_confidence' => 0.55,
            ],
            PlanObjectives::quality(),
        );

        // Breaking news: latency constrained
        $this->assertSame(10_000, $captured->maxLatencyMs);
        // Premium: quality weights
        $this->assertSame(0.50, $captured->narrativeWeight);
        // Low confidence: minReviewScore raised
        $this->assertGreaterThan(0.80, $captured->minReviewScore);
    }

    // ── Deferred execution relaxes latency ───────────────────────────────────

    public function test_deferred_execution_relaxes_latency_constraint(): void
    {
        $engine = new PolicyEngine();
        $engine->register(new Policy('gpu_overload_defer',
            AttributeCondition::gte('gpu_cluster.temp_c', 90.0),
            new DeferExecutionAction(30_000.0), 500,
        ));

        [$planner, $captured] = $this->buildPlanner($engine);
        $base = PlanObjectives::breakingNews(); // maxLatencyMs = 15 000

        $planner->plan(
            $this->minimalGoalGraph(),
            $this->minimalMeaningGraph(),
            ['gpu_cluster.temp_c' => 95.0],
            $base,
        );

        $this->assertSame(PHP_INT_MAX, $captured->maxLatencyMs,
            'Deferred execution must remove the latency hard cap');
    }

    // ── EventBus receives PlanningDecisionEvent ───────────────────────────────

    public function test_planning_decision_event_dispatched_to_event_bus(): void
    {
        $engine = new PolicyEngine();
        $engine->register(new Policy('breaking_news_latency',
            AttributeCondition::eq('content_type', 'breaking_news'),
            new SetMaxLatencyAction(10_000.0), 200,
        ));

        $bus = new EventBus(recordHistory: true);
        [$planner] = $this->buildPlanner($engine, $bus);

        $planner->plan(
            $this->minimalGoalGraph(),
            $this->minimalMeaningGraph(),
            ['content_type' => 'breaking_news'],
            PlanObjectives::quality(),
        );

        $events = $bus->historyOf('planning.decision');
        $this->assertCount(1, $events);

        /** @var PlanningDecisionEvent $event */
        $event = $events[0];
        $this->assertInstanceOf(PlanningDecisionEvent::class, $event);
        $this->assertContains('breaking_news_latency', $event->appliedPolicies);
        $this->assertTrue($event->payload()['latencyConstrained']);
        $this->assertSame(10_000, $event->constrainedMaxLatencyMs);
    }

    public function test_no_policies_dispatches_event_with_empty_applied(): void
    {
        $engine = new PolicyEngine();
        $bus    = new EventBus(recordHistory: true);
        [$planner] = $this->buildPlanner($engine, $bus);

        $planner->plan(
            $this->minimalGoalGraph(),
            $this->minimalMeaningGraph(),
            [],
            PlanObjectives::breakingNews(),
        );

        $events = $bus->historyOf('planning.decision');
        $this->assertCount(1, $events);
        $this->assertEmpty($events[0]->appliedPolicies);
        $this->assertFalse($events[0]->payload()['latencyConstrained']);
    }

    // ── PolicyToPlanAdapter unit tests ────────────────────────────────────────

    public function test_adapter_quality_bias_weights(): void
    {
        $adapter  = new PolicyToPlanAdapter();
        $decision = new \App\Services\AI\FilmOS\Policy\PolicyDecision();
        $decision->qualityCostBias = 'quality';

        $result = $adapter->adapt($decision, PlanObjectives::breakingNews());

        $this->assertSame(0.50, $result->narrativeWeight);
        $this->assertSame(0.10, $result->costWeight);
        $this->assertSame(0.15, $result->latencyWeight);
        $this->assertSame(0.25, $result->reviewScoreWeight);
    }

    public function test_adapter_cost_bias_weights(): void
    {
        $adapter  = new PolicyToPlanAdapter();
        $decision = new \App\Services\AI\FilmOS\Policy\PolicyDecision();
        $decision->qualityCostBias = 'cost';

        $result = $adapter->adapt($decision, PlanObjectives::quality());

        $this->assertSame(0.25, $result->narrativeWeight);
        $this->assertSame(0.45, $result->costWeight);
    }

    public function test_adapter_latency_takes_stricter_of_policy_and_base(): void
    {
        $adapter  = new PolicyToPlanAdapter();

        $decision = new \App\Services\AI\FilmOS\Policy\PolicyDecision();
        $decision->maxLatencyMs = 8_000.0; // stricter than base
        $base = PlanObjectives::breakingNews(); // maxLatencyMs = 15 000

        $result = $adapter->adapt($decision, $base);
        $this->assertSame(8_000, $result->maxLatencyMs);

        // If policy is more lenient than base, base wins
        $lenientDecision = new \App\Services\AI\FilmOS\Policy\PolicyDecision();
        $lenientDecision->maxLatencyMs = 999_999.0;
        $result2 = $adapter->adapt($lenientDecision, $base);
        $this->assertSame($base->maxLatencyMs, $result2->maxLatencyMs);
    }

    public function test_adapter_review_score_raised_per_extra_reviewer(): void
    {
        $adapter  = new PolicyToPlanAdapter();
        $base     = PlanObjectives::quality(); // minReviewScore = 0.80

        $decision = new \App\Services\AI\FilmOS\Policy\PolicyDecision();
        $decision->requiredReviewers = 4; // +3 extra → +0.15

        $result = $adapter->adapt($decision, $base);
        $this->assertEqualsWithDelta(0.95, $result->minReviewScore, 0.001);
    }

    public function test_adapter_review_score_capped_at_0_95(): void
    {
        $adapter  = new PolicyToPlanAdapter();
        $base     = PlanObjectives::quality(); // 0.80

        $decision = new \App\Services\AI\FilmOS\Policy\PolicyDecision();
        $decision->requiredReviewers = 20; // would push to 1.75 without cap

        $result = $adapter->adapt($decision, $base);
        $this->assertSame(0.95, $result->minReviewScore);
    }
}
