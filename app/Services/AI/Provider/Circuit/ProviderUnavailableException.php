<?php

namespace App\Services\AI\Provider\Circuit;

/**
 * Thrown by CircuitBreakerAwareProvider when the circuit is OPEN.
 *
 * Jobs should catch this and re-queue themselves with releaseDelay() seconds
 * rather than consuming a retry attempt or marking the run as failed.
 */
final class ProviderUnavailableException extends \RuntimeException
{
    public function __construct(
        private readonly string       $provider,
        private readonly CircuitState $circuitState,
        private readonly int          $releaseDelay = 60,
    ) {
        parent::__construct(
            "Provider '{$provider}' is unavailable (circuit: {$circuitState->value})"
        );
    }

    public function provider(): string { return $this->provider; }
    public function circuitState(): CircuitState { return $this->circuitState; }

    /**
     * Seconds jobs should wait before re-queuing themselves.
     * Returns the base delay ± 10% jitter to prevent thundering herd when many workers
     * all recover from a circuit-open at the same moment.
     */
    public function releaseDelay(): int
    {
        $base = $this->releaseDelay;
        $jitter = (int) round($base * 0.1);
        return random_int($base - $jitter, $base + $jitter);
    }
}
