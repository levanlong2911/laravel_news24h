<?php

namespace App\Services\AI\AFOS\Passes\Prompt;

/**
 * PlannerRegistry — maps backend IDs to PromptPlannerInterface implementations.
 *
 * Symmetric with BackendRegistry (EMIT phase) — same immutable value object pattern.
 * register() returns a new registry; the original is never mutated.
 *
 * Usage:
 *   $registry = PlannerRegistry::withDefaults();                   // Kling only
 *   $registry = PlannerRegistry::withDefaults()
 *                   ->register(new VeoPromptPlanner())             // Kling + Veo
 *                   ->register(new RunwayPromptPlanner());         // Kling + Veo + Runway
 *
 * PlannerResolver takes a PlannerRegistry; Tier3Stage takes a PlannerResolver.
 * Tier3Stage knows nothing about which planners exist.
 */
final class PlannerRegistry
{
    /** @param array<string, PromptPlannerInterface> $planners */
    private function __construct(private array $planners = []) {}

    /**
     * Return a new registry with $planner added (or replaced if same backendId).
     * Immutable — does not mutate the original registry.
     */
    public function register(PromptPlannerInterface $planner): self
    {
        $clone = clone $this;
        $clone->planners[$planner->backendId()] = $planner;
        return $clone;
    }

    /**
     * Retrieve a planner by backend ID.
     *
     * @throws \InvalidArgumentException if no planner is registered for $backendId.
     */
    public function planner(string $backendId): PromptPlannerInterface
    {
        return $this->planners[$backendId] ?? throw new \InvalidArgumentException(
            sprintf(
                "No prompt planner registered for backend '%s'. Registered: [%s].",
                $backendId,
                $this->planners === [] ? 'none' : implode(', ', array_keys($this->planners)),
            )
        );
    }

    public function has(string $backendId): bool
    {
        return isset($this->planners[$backendId]);
    }

    /** @return string[] All registered backend IDs. */
    public function registeredBackendIds(): array
    {
        return array_keys($this->planners);
    }

    /** Default registry: KlingPromptPlanningPass registered under 'kling'. */
    public static function withDefaults(): self
    {
        return (new self)->register(new KlingPromptPlanningPass());
    }
}
