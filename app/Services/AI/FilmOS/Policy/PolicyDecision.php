<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Policy;

use App\Services\AI\FilmOS\Snapshot\CanonicalArray;

/**
 * Mutable accumulator that PolicyEngine builds as policies fire.
 *
 * Starts with neutral defaults. Each applied action narrows or constrains
 * these defaults. The final state is the engine's recommendation.
 *
 * Planner reads this and acts accordingly — it never contains if/else logic.
 *
 * Audit trail (appliedPolicies / skippedPolicies) is always populated,
 * enabling full traceability of every decision.
 */
final class PolicyDecision
{
    /** If set, the planner must use this provider (or fail). */
    public string $preferredProvider = '';

    /** Max allowed latency in milliseconds. PHP_FLOAT_MAX = no constraint. */
    public float $maxLatencyMs = PHP_FLOAT_MAX;

    /** 'quality' | 'cost' | 'balanced' — trade-off bias for the planner. */
    public string $qualityCostBias = 'balanced';

    /** Whether execution should be deferred (e.g., GPU cluster overloaded). */
    public bool $deferExecution = false;

    /** How long to defer in milliseconds. 0 unless deferExecution is true. */
    public float $deferForMs = 0.0;

    /** Minimum number of reviewers required for this execution. */
    public int $requiredReviewers = 1;

    /** Providers the planner must NOT select for this execution. */
    public array $disabledProviders = [];

    /** Free-form metadata for planner-specific policy hints. */
    public array $metadata = [];

    // ── Audit trail ───────────────────────────────────────────────────────────

    /** @var string[] names of policies whose condition returned true */
    public array $appliedPolicies = [];

    /** @var string[] names of policies whose condition returned false */
    public array $skippedPolicies = [];

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function hasProviderConstraint(): bool
    {
        return $this->preferredProvider !== '' || !empty($this->disabledProviders);
    }

    public function hasLatencyConstraint(): bool
    {
        return $this->maxLatencyMs < PHP_FLOAT_MAX;
    }

    public function isProviderAllowed(string $provider): bool
    {
        if ($this->preferredProvider !== '' && $this->preferredProvider !== $provider) {
            return false;
        }
        return !in_array($provider, $this->disabledProviders, strict: true);
    }

    /**
     * Canonical representation for deterministic hashing.
     *
     * Excludes audit trail (appliedPolicies, skippedPolicies) — those are
     * observational metadata, not decisions. disabledProviders is sorted
     * alphabetically so insertion order never affects the hash.
     *
     * @return array<string, mixed>
     */
    public function toCanonicalArray(): array
    {
        $disabled = $this->disabledProviders;
        sort($disabled);

        // metadata is a free-form keyed array; deep-sort so nested insertion order never affects the hash.
        $metadata = CanonicalArray::deepSort($this->metadata);

        return [
            'preferredProvider' => $this->preferredProvider ?: null,
            'maxLatencyMs'      => $this->maxLatencyMs === PHP_FLOAT_MAX ? null : $this->maxLatencyMs,
            'qualityCostBias'   => $this->qualityCostBias,
            'deferExecution'    => $this->deferExecution,
            'deferForMs'        => $this->deferForMs,
            'requiredReviewers' => $this->requiredReviewers,
            'disabledProviders' => $disabled,
            'metadata'          => $metadata,
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'preferredProvider' => $this->preferredProvider ?: null,
            'maxLatencyMs'      => $this->maxLatencyMs === PHP_FLOAT_MAX ? null : $this->maxLatencyMs,
            'qualityCostBias'   => $this->qualityCostBias,
            'deferExecution'    => $this->deferExecution,
            'deferForMs'        => $this->deferForMs,
            'requiredReviewers' => $this->requiredReviewers,
            'disabledProviders' => $this->disabledProviders,
            'metadata'          => $this->metadata,
            'appliedPolicies'   => $this->appliedPolicies,
            'skippedPolicies'   => $this->skippedPolicies,
        ];
    }
}
