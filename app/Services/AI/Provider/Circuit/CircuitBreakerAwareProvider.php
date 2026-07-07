<?php

namespace App\Services\AI\Provider\Circuit;

use App\Services\AI\Provider\Dto\RenderArtifact;
use App\Services\AI\Provider\Dto\RenderStatusResult;
use App\Services\AI\Provider\Dto\RenderSubmitResult;
use App\Services\AI\Provider\Dto\RenderVideoRequest;
use App\Services\AI\Provider\RenderVideoProvider;

/**
 * Decorator that wraps any RenderVideoProvider with circuit-breaker protection.
 *
 * On each API call:
 *   - Checks the circuit before calling the inner provider.
 *   - Records success/failure so the circuit can track provider health.
 *
 * nextPollDelay() is pure computation — it bypasses the circuit entirely.
 * cancel() is best-effort — failures are swallowed and never open the circuit.
 */
final class CircuitBreakerAwareProvider implements RenderVideoProvider
{
    public function __construct(
        private readonly RenderVideoProvider $inner,
        private readonly CircuitBreaker      $circuit,
    ) {}

    public function providerId(): string
    {
        return $this->inner->providerId();
    }

    public function submit(RenderVideoRequest $request): RenderSubmitResult
    {
        return $this->call(fn () => $this->inner->submit($request));
    }

    public function status(string $taskId): RenderStatusResult
    {
        return $this->call(fn () => $this->inner->status($taskId));
    }

    public function artifact(string $taskId): RenderArtifact
    {
        return $this->call(fn () => $this->inner->artifact($taskId));
    }

    public function cancel(string $taskId): void
    {
        try {
            $this->inner->cancel($taskId);
        } catch (\Throwable) {
            // Best-effort — don't penalise the circuit for cancel failures.
        }
    }

    public function nextPollDelay(int $attempt, RenderStatusResult $status): int
    {
        // Pure local computation — no API call, no circuit involvement.
        return $this->inner->nextPollDelay($attempt, $status);
    }

    /**
     * @template T
     * @param  \Closure(): T $fn
     * @return T
     */
    private function call(\Closure $fn): mixed
    {
        $state = $this->circuit->state();

        if (! $this->circuit->isAvailable()) {
            throw new ProviderUnavailableException($this->inner->providerId(), $state);
        }

        try {
            $result = $fn();
            $this->circuit->recordSuccess();
            return $result;
        } catch (ProviderUnavailableException $e) {
            // Already a circuit exception (e.g., nested call) — don't double-record.
            throw $e;
        } catch (\Throwable $e) {
            $this->circuit->recordFailure($e->getMessage());
            throw $e;
        }
    }
}
