<?php

declare(strict_types=1);

namespace Tests\Infrastructure\FilmOS;

use App\Services\AI\FilmOS\Policy\Actions\DeferExecutionAction;
use App\Services\AI\FilmOS\Policy\Actions\DisableProviderAction;
use App\Services\AI\FilmOS\Policy\Actions\RequireReviewersAction;
use App\Services\AI\FilmOS\Policy\Actions\SetMaxLatencyAction;
use App\Services\AI\FilmOS\Policy\Actions\SetPreferredProviderAction;
use App\Services\AI\FilmOS\Policy\Actions\SetQualityBiasAction;
use App\Services\AI\FilmOS\Policy\Conditions\AlwaysCondition;
use App\Services\AI\FilmOS\Policy\Conditions\AttributeCondition;
use App\Services\AI\FilmOS\Policy\Conditions\CompositeAndCondition;
use App\Services\AI\FilmOS\Policy\Conditions\CompositeOrCondition;
use App\Services\AI\FilmOS\Policy\Policy;
use App\Services\AI\FilmOS\Policy\PolicyContext;
use App\Services\AI\FilmOS\Policy\PolicyDecision;
use App\Services\AI\FilmOS\Policy\PolicyEngine;
use PHPUnit\Framework\TestCase;

/**
 * Proves the four named scenarios the user identified as the core Policy Engine value:
 *
 *   1. Breaking News     → maxLatencyMs < 10 000
 *   2. Premium Customer  → only Veo
 *   3. Budget exhausted  → switch to Kling (disable Veo + Runway)
 *   4. Low confidence    → add reviewer
 */
final class PolicyEngineTest extends TestCase
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    private function engineWithBreakingNewsPolicy(): PolicyEngine
    {
        $engine = new PolicyEngine();
        $engine->register(new Policy(
            name:      'breaking_news_latency',
            condition: AttributeCondition::eq('content_type', 'breaking_news'),
            action:    new SetMaxLatencyAction(10_000.0),
            priority:  200,
            description: 'Breaking news requires sub-10s delivery.',
        ));
        return $engine;
    }

    // ── 1. Breaking News → latency < 10s ─────────────────────────────────────

    public function test_breaking_news_sets_max_latency_10s(): void
    {
        $engine  = $this->engineWithBreakingNewsPolicy();
        $context = PolicyContext::from(['content_type' => 'breaking_news']);

        $decision = $engine->decide($context);

        $this->assertSame(10_000.0, $decision->maxLatencyMs);
        $this->assertTrue($decision->hasLatencyConstraint());
        $this->assertContains('breaking_news_latency', $decision->appliedPolicies);
    }

    public function test_non_breaking_news_leaves_latency_unconstrained(): void
    {
        $engine  = $this->engineWithBreakingNewsPolicy();
        $context = PolicyContext::from(['content_type' => 'documentary']);

        $decision = $engine->decide($context);

        $this->assertSame(PHP_FLOAT_MAX, $decision->maxLatencyMs);
        $this->assertFalse($decision->hasLatencyConstraint());
        $this->assertContains('breaking_news_latency', $decision->skippedPolicies);
    }

    // ── 2. Premium Customer → only Veo ───────────────────────────────────────

    public function test_premium_customer_forces_veo_only(): void
    {
        $engine = new PolicyEngine();
        $engine->register(new Policy(
            name:      'premium_veo_only',
            condition: AttributeCondition::eq('customer_tier', 'premium'),
            action:    new SetPreferredProviderAction('veo'),
            priority:  300,
            description: 'Premium customers get Veo quality.',
        ));

        $context  = PolicyContext::from(['customer_tier' => 'premium']);
        $decision = $engine->decide($context);

        $this->assertSame('veo', $decision->preferredProvider);
        $this->assertTrue($decision->hasProviderConstraint());
        $this->assertFalse($decision->isProviderAllowed('kling'));
        $this->assertFalse($decision->isProviderAllowed('runway'));
        $this->assertTrue($decision->isProviderAllowed('veo'));
        $this->assertContains('premium_veo_only', $decision->appliedPolicies);
    }

    public function test_standard_customer_has_no_provider_constraint(): void
    {
        $engine = new PolicyEngine();
        $engine->register(new Policy(
            name:      'premium_veo_only',
            condition: AttributeCondition::eq('customer_tier', 'premium'),
            action:    new SetPreferredProviderAction('veo'),
            priority:  300,
        ));

        $context  = PolicyContext::from(['customer_tier' => 'standard']);
        $decision = $engine->decide($context);

        $this->assertSame('', $decision->preferredProvider);
        $this->assertFalse($decision->hasProviderConstraint());
        $this->assertTrue($decision->isProviderAllowed('kling'));
        $this->assertTrue($decision->isProviderAllowed('veo'));
    }

    // ── 3. Budget exhausted → switch to Kling ────────────────────────────────

    public function test_budget_exhausted_disables_expensive_providers(): void
    {
        $engine = new PolicyEngine();
        $engine->register(new Policy(
            name:      'budget_exhausted_fallback',
            condition: AttributeCondition::lte('budget_remaining_usd', 0.0),
            action:    new DisableProviderAction(['veo', 'runway']),
            priority:  400,
            description: 'When budget is gone, disable expensive providers (Kling is free-tier).',
        ));

        $context  = PolicyContext::from(['budget_remaining_usd' => 0.0]);
        $decision = $engine->decide($context);

        $this->assertContains('veo', $decision->disabledProviders);
        $this->assertContains('runway', $decision->disabledProviders);
        $this->assertFalse($decision->isProviderAllowed('veo'));
        $this->assertFalse($decision->isProviderAllowed('runway'));
        $this->assertTrue($decision->isProviderAllowed('kling'));
        $this->assertContains('budget_exhausted_fallback', $decision->appliedPolicies);
    }

    public function test_negative_budget_also_disables_expensive_providers(): void
    {
        $engine = new PolicyEngine();
        $engine->register(new Policy(
            name:      'budget_exhausted_fallback',
            condition: AttributeCondition::lte('budget_remaining_usd', 0.0),
            action:    new DisableProviderAction(['veo', 'runway']),
            priority:  400,
        ));

        $decision = $engine->decide(PolicyContext::from(['budget_remaining_usd' => -5.0]));

        $this->assertContains('veo', $decision->disabledProviders);
    }

    public function test_remaining_budget_leaves_all_providers_enabled(): void
    {
        $engine = new PolicyEngine();
        $engine->register(new Policy(
            name:      'budget_exhausted_fallback',
            condition: AttributeCondition::lte('budget_remaining_usd', 0.0),
            action:    new DisableProviderAction(['veo', 'runway']),
            priority:  400,
        ));

        $decision = $engine->decide(PolicyContext::from(['budget_remaining_usd' => 50.0]));

        $this->assertEmpty($decision->disabledProviders);
        $this->assertTrue($decision->isProviderAllowed('veo'));
        $this->assertTrue($decision->isProviderAllowed('runway'));
        $this->assertTrue($decision->isProviderAllowed('kling'));
    }

    // ── 4. Low confidence → add reviewer ─────────────────────────────────────

    public function test_low_confidence_requires_extra_reviewer(): void
    {
        $engine = new PolicyEngine();
        $engine->register(new Policy(
            name:      'low_confidence_review',
            condition: AttributeCondition::lt('reviewer_confidence', 0.7),
            action:    new RequireReviewersAction(3),
            priority:  150,
            description: 'AI confidence below 70% requires 3 reviewers.',
        ));

        $context  = PolicyContext::from(['reviewer_confidence' => 0.55]);
        $decision = $engine->decide($context);

        $this->assertSame(3, $decision->requiredReviewers);
        $this->assertContains('low_confidence_review', $decision->appliedPolicies);
    }

    public function test_high_confidence_keeps_default_reviewer_count(): void
    {
        $engine = new PolicyEngine();
        $engine->register(new Policy(
            name:      'low_confidence_review',
            condition: AttributeCondition::lt('reviewer_confidence', 0.7),
            action:    new RequireReviewersAction(3),
            priority:  150,
        ));

        $decision = $engine->decide(PolicyContext::from(['reviewer_confidence' => 0.9]));

        $this->assertSame(1, $decision->requiredReviewers); // default
        $this->assertContains('low_confidence_review', $decision->skippedPolicies);
    }

    // ── Multi-policy interaction ──────────────────────────────────────────────

    public function test_all_four_policies_applied_simultaneously(): void
    {
        $engine = new PolicyEngine();
        $engine->register(new Policy('breaking_news_latency',
            AttributeCondition::eq('content_type', 'breaking_news'),
            new SetMaxLatencyAction(10_000.0),
            200,
        ));
        $engine->register(new Policy('premium_veo_only',
            AttributeCondition::eq('customer_tier', 'premium'),
            new SetPreferredProviderAction('veo'),
            300,
        ));
        $engine->register(new Policy('low_confidence_review',
            AttributeCondition::lt('reviewer_confidence', 0.7),
            new RequireReviewersAction(3),
            150,
        ));
        $engine->register(new Policy('cost_bias_default',
            new AlwaysCondition(),
            new SetQualityBiasAction('balanced'),
            10,
        ));

        $context = PolicyContext::from([
            'content_type'       => 'breaking_news',
            'customer_tier'      => 'premium',
            'reviewer_confidence' => 0.6,
        ]);

        $decision = $engine->decide($context);

        $this->assertSame(10_000.0, $decision->maxLatencyMs);
        $this->assertSame('veo', $decision->preferredProvider);
        $this->assertSame(3, $decision->requiredReviewers);
        $this->assertSame('balanced', $decision->qualityCostBias);

        // All three triggered + default always policy
        $this->assertCount(4, $decision->appliedPolicies);
        $this->assertEmpty($decision->skippedPolicies);
    }

    public function test_audit_trail_always_populated(): void
    {
        $engine = new PolicyEngine();
        $engine->register(new Policy('p1',
            AttributeCondition::eq('x', 1), new SetQualityBiasAction('quality'), 100,
        ));
        $engine->register(new Policy('p2',
            AttributeCondition::eq('x', 2), new SetQualityBiasAction('cost'), 90,
        ));

        $decision = $engine->decide(PolicyContext::from(['x' => 1]));

        $this->assertContains('p1', $decision->appliedPolicies);
        $this->assertContains('p2', $decision->skippedPolicies);
        $this->assertCount(1, $decision->appliedPolicies);
        $this->assertCount(1, $decision->skippedPolicies);
    }

    // ── Priority ordering ─────────────────────────────────────────────────────

    public function test_higher_priority_policy_applied_first(): void
    {
        $engine = new PolicyEngine();

        // Both fire; high priority sets 'quality', low priority sets 'cost'.
        // Since ALL policies run and low-priority runs LAST, it overwrites.
        // This is correct behaviour: lower-priority runs later = can override.
        $engine->register(new Policy('high', new AlwaysCondition(),
            new SetQualityBiasAction('quality'), 200,
        ));
        $engine->register(new Policy('low', new AlwaysCondition(),
            new SetQualityBiasAction('cost'), 100,
        ));

        $decision = $engine->decide(PolicyContext::from([]));

        // low-priority fires last → cost wins (later mutation wins)
        $this->assertSame('cost', $decision->qualityCostBias);
        $this->assertSame(['high', 'low'], $decision->appliedPolicies);
    }

    // ── Composite conditions ──────────────────────────────────────────────────

    public function test_and_condition_requires_all_sub_conditions(): void
    {
        $engine = new PolicyEngine();
        $engine->register(new Policy('compound',
            new CompositeAndCondition([
                AttributeCondition::eq('content_type', 'breaking_news'),
                AttributeCondition::lt('budget_remaining_usd', 50.0),
            ]),
            new SetMaxLatencyAction(5_000.0),
            100,
        ));

        // Both true → fires
        $decision = $engine->decide(PolicyContext::from([
            'content_type'        => 'breaking_news',
            'budget_remaining_usd' => 20.0,
        ]));
        $this->assertSame(5_000.0, $decision->maxLatencyMs);

        // One false → does not fire
        $decision2 = $engine->decide(PolicyContext::from([
            'content_type'        => 'breaking_news',
            'budget_remaining_usd' => 100.0,
        ]));
        $this->assertSame(PHP_FLOAT_MAX, $decision2->maxLatencyMs);
    }

    public function test_or_condition_fires_when_any_sub_condition_true(): void
    {
        $engine = new PolicyEngine();
        $engine->register(new Policy('vip_or_premium',
            new CompositeOrCondition([
                AttributeCondition::eq('customer_tier', 'vip'),
                AttributeCondition::eq('customer_tier', 'premium'),
            ]),
            new SetPreferredProviderAction('veo'),
            100,
        ));

        $d1 = $engine->decide(PolicyContext::from(['customer_tier' => 'vip']));
        $this->assertSame('veo', $d1->preferredProvider);

        $d2 = $engine->decide(PolicyContext::from(['customer_tier' => 'premium']));
        $this->assertSame('veo', $d2->preferredProvider);

        $d3 = $engine->decide(PolicyContext::from(['customer_tier' => 'standard']));
        $this->assertSame('', $d3->preferredProvider);
    }

    // ── AttributeCondition operators ─────────────────────────────────────────

    public function test_in_operator_matches_any_list_value(): void
    {
        $cond = AttributeCondition::in('region', ['us-east', 'us-west']);
        $this->assertTrue($cond->evaluate(PolicyContext::from(['region' => 'us-east'])));
        $this->assertTrue($cond->evaluate(PolicyContext::from(['region' => 'us-west'])));
        $this->assertFalse($cond->evaluate(PolicyContext::from(['region' => 'eu-west'])));
    }

    public function test_exists_operator_checks_key_presence(): void
    {
        $cond = AttributeCondition::exists('gpu_cluster.temp_c');
        $this->assertTrue($cond->evaluate(PolicyContext::from(['gpu_cluster.temp_c' => 85.0])));
        $this->assertFalse($cond->evaluate(PolicyContext::from([])));
    }

    public function test_neq_operator(): void
    {
        $cond = AttributeCondition::neq('status', 'maintenance');
        $this->assertTrue($cond->evaluate(PolicyContext::from(['status' => 'active'])));
        $this->assertFalse($cond->evaluate(PolicyContext::from(['status' => 'maintenance'])));
    }

    // ── RequireReviewers accumulates (strictest wins) ─────────────────────────

    public function test_require_reviewers_takes_maximum(): void
    {
        $engine = new PolicyEngine();
        $engine->register(new Policy('r1', new AlwaysCondition(), new RequireReviewersAction(2), 100));
        $engine->register(new Policy('r2', new AlwaysCondition(), new RequireReviewersAction(5), 100));
        $engine->register(new Policy('r3', new AlwaysCondition(), new RequireReviewersAction(3), 100));

        $decision = $engine->decide(PolicyContext::from([]));
        $this->assertSame(5, $decision->requiredReviewers);
    }

    // ── Disable provider deduplication ───────────────────────────────────────

    public function test_disable_provider_deduplication(): void
    {
        $engine = new PolicyEngine();
        $engine->register(new Policy('d1', new AlwaysCondition(), new DisableProviderAction(['veo']), 100));
        $engine->register(new Policy('d2', new AlwaysCondition(), new DisableProviderAction(['veo', 'runway']), 90));

        $decision = $engine->decide(PolicyContext::from([]));

        // veo appears only once despite two policies adding it
        $this->assertCount(2, $decision->disabledProviders);
        $this->assertSame(['veo', 'runway'], $decision->disabledProviders);
    }

    // ── toArray serialisation ─────────────────────────────────────────────────

    public function test_decision_to_array_serialises_all_fields(): void
    {
        $engine = new PolicyEngine();
        $engine->register(new Policy('breaking_news_latency',
            AttributeCondition::eq('content_type', 'breaking_news'),
            new SetMaxLatencyAction(10_000.0),
            200,
        ));

        $decision = $engine->decide(PolicyContext::from(['content_type' => 'breaking_news']));
        $arr      = $decision->toArray();

        $this->assertSame(10_000.0, $arr['maxLatencyMs']);
        $this->assertContains('breaking_news_latency', $arr['appliedPolicies']);
        $this->assertArrayHasKey('preferredProvider', $arr);
        $this->assertArrayHasKey('deferExecution', $arr);
        $this->assertArrayHasKey('disabledProviders', $arr);
    }

    // ── Empty engine ─────────────────────────────────────────────────────────

    public function test_empty_engine_returns_neutral_decision(): void
    {
        $engine   = new PolicyEngine();
        $decision = $engine->decide(PolicyContext::from(['content_type' => 'anything']));

        $this->assertSame('', $decision->preferredProvider);
        $this->assertSame(PHP_FLOAT_MAX, $decision->maxLatencyMs);
        $this->assertSame('balanced', $decision->qualityCostBias);
        $this->assertFalse($decision->deferExecution);
        $this->assertSame(1, $decision->requiredReviewers);
        $this->assertEmpty($decision->disabledProviders);
        $this->assertEmpty($decision->appliedPolicies);
        $this->assertEmpty($decision->skippedPolicies);
    }

    // ── Defer execution ───────────────────────────────────────────────────────

    public function test_defer_execution_action(): void
    {
        $engine = new PolicyEngine();
        $engine->register(new Policy('gpu_overloaded',
            AttributeCondition::gte('gpu_cluster.temp_c', 90.0),
            new DeferExecutionAction(30_000.0),
            500,
        ));

        $decision = $engine->decide(PolicyContext::from(['gpu_cluster.temp_c' => 95.0]));

        $this->assertTrue($decision->deferExecution);
        $this->assertSame(30_000.0, $decision->deferForMs);
    }

    public function test_no_defer_when_gpu_safe(): void
    {
        $engine = new PolicyEngine();
        $engine->register(new Policy('gpu_overloaded',
            AttributeCondition::gte('gpu_cluster.temp_c', 90.0),
            new DeferExecutionAction(30_000.0),
            500,
        ));

        $decision = $engine->decide(PolicyContext::from(['gpu_cluster.temp_c' => 70.0]));

        $this->assertFalse($decision->deferExecution);
        $this->assertSame(0.0, $decision->deferForMs);
    }

    // ── PolicyContext immutability ────────────────────────────────────────────

    public function test_policy_context_with_returns_new_instance(): void
    {
        $original = PolicyContext::from(['a' => 1]);
        $derived  = $original->with('b', 2);

        $this->assertFalse($original->has('b'));
        $this->assertTrue($derived->has('b'));
        $this->assertSame(1, $derived->get('a'));
    }
}
