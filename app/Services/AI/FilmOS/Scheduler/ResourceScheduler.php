<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Scheduler;

use App\Services\AI\FilmOS\Capability\CapabilityDescriptor;
use App\Services\AI\FilmOS\Capability\CapabilityRegistry;
use App\Services\AI\FilmOS\Capability\CapabilityType;
use App\Services\AI\FilmOS\Policy\PolicyDecision;

/**
 * Picks the best available provider for a capability, respecting daily quotas.
 *
 * The scheduler is the only place quota state lives.
 * Planners call schedule() — they never read quotas directly.
 *
 * Quota enforcement model (priority-waterfall):
 *   1. Walk providers sorted by priority (highest first).
 *   2. First provider with remaining quota → selected.
 *   3. All exhausted → return null (caller should backoff or fail).
 *
 * Daily reset via resetDailyUsage() — call at UTC midnight or from a cron.
 */
final class ResourceScheduler
{
    /** @var array<string, int> providerName → calls used today */
    private array $dailyUsage = [];

    /** @var array<string, float> providerName → total accumulated cost (USD) */
    private array $totalCostUsd = [];

    public function __construct(
        private readonly CapabilityRegistry $registry,
    ) {}

    // ── Scheduling ────────────────────────────────────────────────────────────

    /**
     * Select the best available provider for a capability.
     *
     * @return SchedulerDecision|null null = all providers exhausted for today
     */
    public function schedule(CapabilityType $capability): ?SchedulerDecision
    {
        foreach ($this->registry->resolve($capability) as $descriptor) {
            $used = $this->dailyUsage[$descriptor->providerName] ?? 0;

            if ($used < $descriptor->dailyQuota) {
                return new SchedulerDecision(
                    provider:         $descriptor->providerName,
                    capability:       $capability,
                    estimatedCostUsd: $descriptor->costPerCallUsd,
                    quotaUsedBefore:  $used,
                    quotaMax:         $descriptor->dailyQuota,
                );
            }
        }

        return null;
    }

    /**
     * Policy-aware scheduling: same as schedule() but enforces governance rules.
     *
     * The PolicyDecision was built once upstream (by PolicyEngine via PolicyAwarePlanner)
     * and flows down as a single source of truth — this method never re-queries PolicyEngine.
     *
     * Enforcement order:
     *   1. deferExecution = true  → always null (execution must not start now)
     *   2. disabledProviders      → hard-blocked; removed from candidates
     *   3. preferredProvider      → promoted to front of candidate list (soft preference;
     *                               falls back to priority-waterfall if preferred is exhausted)
     *   4. priority-waterfall     → first candidate with remaining quota wins
     *
     * @return SchedulerDecision|null null = deferred, or all candidates exhausted / disabled
     */
    public function scheduleWithPolicy(CapabilityType $capability, PolicyDecision $decision): ?SchedulerDecision
    {
        if ($decision->deferExecution) {
            return null;
        }

        // Hard-block disabled providers first.
        $candidates = array_values(array_filter(
            $this->registry->resolve($capability),
            static fn(CapabilityDescriptor $d) => !in_array($d->providerName, $decision->disabledProviders, strict: true),
        ));

        // Lift preferred provider to front; rest keeps registry priority order.
        if ($decision->preferredProvider !== '') {
            $preferred = array_values(array_filter($candidates,
                static fn(CapabilityDescriptor $d) => $d->providerName === $decision->preferredProvider));
            $rest      = array_values(array_filter($candidates,
                static fn(CapabilityDescriptor $d) => $d->providerName !== $decision->preferredProvider));
            $candidates = array_merge($preferred, $rest);
        }

        foreach ($candidates as $descriptor) {
            $used = $this->dailyUsage[$descriptor->providerName] ?? 0;
            if ($used < $descriptor->dailyQuota) {
                return new SchedulerDecision(
                    provider:         $descriptor->providerName,
                    capability:       $capability,
                    estimatedCostUsd: $descriptor->costPerCallUsd,
                    quotaUsedBefore:  $used,
                    quotaMax:         $descriptor->dailyQuota,
                );
            }
        }

        return null;
    }

    /**
     * Record a completed call.
     * Must be called after each successful API request so quota is tracked.
     */
    public function recordUsage(string $providerName, float $actualCostUsd = 0.0): void
    {
        $this->dailyUsage[$providerName]  = ($this->dailyUsage[$providerName] ?? 0) + 1;
        $this->totalCostUsd[$providerName] = ($this->totalCostUsd[$providerName] ?? 0.0) + $actualCostUsd;
    }

    // ── Introspection ─────────────────────────────────────────────────────────

    public function usageFor(string $providerName): int
    {
        return $this->dailyUsage[$providerName] ?? 0;
    }

    public function totalCostFor(string $providerName): float
    {
        return $this->totalCostUsd[$providerName] ?? 0.0;
    }

    public function totalCostAllProviders(): float
    {
        return array_sum($this->totalCostUsd);
    }

    /**
     * @return array<string, array{used: int, max: int|string, remaining: int|string, costUsd: float}>
     */
    public function quotaSnapshot(): array
    {
        $snapshot = [];
        foreach ($this->registry->providers() as $name) {
            $maxQuota = $this->maxQuotaFor($name);
            $used     = $this->dailyUsage[$name] ?? 0;
            $snapshot[$name] = [
                'used'      => $used,
                'max'       => $maxQuota === PHP_INT_MAX ? 'unlimited' : $maxQuota,
                'remaining' => $maxQuota === PHP_INT_MAX ? 'unlimited' : max(0, $maxQuota - $used),
                'costUsd'   => $this->totalCostUsd[$name] ?? 0.0,
            ];
        }
        return $snapshot;
    }

    /** True if at least one provider has remaining quota for the capability. */
    public function hasCapacity(CapabilityType $capability): bool
    {
        return $this->schedule($capability) !== null;
    }

    // ── Lifecycle ─────────────────────────────────────────────────────────────

    /** Reset daily usage counters (call at UTC midnight or in tests). */
    public function resetDailyUsage(): void
    {
        $this->dailyUsage = [];
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function maxQuotaFor(string $providerName): int
    {
        $max = 0;
        foreach (CapabilityType::cases() as $cap) {
            foreach ($this->registry->resolve($cap) as $desc) {
                if ($desc->providerName === $providerName) {
                    $max = max($max, $desc->dailyQuota);
                }
            }
        }
        return $max > 0 ? $max : PHP_INT_MAX;
    }
}
