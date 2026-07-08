<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Policy;

/**
 * Evaluates all registered policies against a context and produces a decision.
 *
 * Algorithm:
 *   1. Sort policies by priority descending (highest first).
 *   2. For each policy: evaluate condition against context.
 *   3. If condition is true → apply action to PolicyDecision, record in appliedPolicies.
 *   4. If condition is false → record in skippedPolicies.
 *   5. Return the accumulated PolicyDecision.
 *
 * ALL policies are evaluated — there is no early exit.
 * Priority controls ORDER of mutation, not whether a policy fires.
 * If two policies conflict (both set preferredProvider), the lower-priority
 * policy overwrites. Higher-priority policies set constraints first.
 *
 * This design allows "default" policies at low priority that get overridden
 * by high-priority context-specific policies.
 */
final class PolicyEngine
{
    /** @var Policy[] */
    private array $policies = [];

    public function register(Policy $policy): void
    {
        $this->policies[] = $policy;
        usort(
            $this->policies,
            static fn(Policy $a, Policy $b) => $b->priority <=> $a->priority,
        );
    }

    /**
     * Evaluate all policies and return the accumulated decision.
     * The returned PolicyDecision always contains a full audit trail.
     */
    public function decide(PolicyContext $context): PolicyDecision
    {
        $decision = new PolicyDecision();

        foreach ($this->policies as $policy) {
            if ($policy->condition->evaluate($context)) {
                $policy->action->apply($decision);
                $decision->appliedPolicies[] = $policy->name;
            } else {
                $decision->skippedPolicies[] = $policy->name;
            }
        }

        return $decision;
    }

    /** Number of registered policies. */
    public function count(): int
    {
        return count($this->policies);
    }

    /** @return Policy[] sorted by priority descending */
    public function policies(): array
    {
        return $this->policies;
    }
}
