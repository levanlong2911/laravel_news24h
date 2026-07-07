<?php

namespace App\Services\AI\Provider\Circuit;

use Carbon\Carbon;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Per-provider circuit breaker backed by the Laravel cache.
 *
 * IMPORTANT: Requires a cache backend that supports atomic increment (Redis, Memcached).
 * The array/file drivers are safe for single-process tests but must not be used in production
 * where multiple workers call recordFailure() concurrently.
 *
 * State machine:
 *   CLOSED → (failures >= threshold) → OPEN
 *   OPEN   → (resetTimeout elapsed)  → HALF_OPEN
 *   HALF_OPEN → (successes >= successThreshold) → CLOSED
 *   HALF_OPEN → (any failure)                   → OPEN
 *
 * Cache keys (all prefixed by "circuit:{name}:"):
 *   state        - CircuitState::value string
 *   failures     - consecutive failure count (CLOSED)
 *   opened_at    - Unix timestamp when OPEN was entered
 *   successes    - probe success count (HALF_OPEN)
 *   last_error   - last failure message (any state, for dashboards)
 *   last_error_at- ISO-8601 timestamp of last_error
 */
final class CircuitBreaker
{
    public function __construct(
        private readonly string          $name,
        private readonly CacheRepository $cache,
        private readonly int             $failureThreshold  = 5,
        private readonly int             $resetTimeout      = 60,
        private readonly int             $successThreshold  = 2,
        private readonly int             $cacheTtl          = 86400,
    ) {}

    public function state(): CircuitState
    {
        $raw   = (string) $this->cache->get($this->key('state'), CircuitState::CLOSED->value);
        $state = CircuitState::from($raw);

        // Auto-transition OPEN → HALF_OPEN after the reset window.
        if ($state === CircuitState::OPEN) {
            $openedAt = (int) $this->cache->get($this->key('opened_at'), 0);
            if (Carbon::now()->timestamp - $openedAt >= $this->resetTimeout) {
                $this->transitionTo(CircuitState::HALF_OPEN);
                return CircuitState::HALF_OPEN;
            }
        }

        return $state;
    }

    public function isAvailable(): bool
    {
        return $this->state() !== CircuitState::OPEN;
    }

    public function recordSuccess(): void
    {
        $state = $this->state();

        if ($state === CircuitState::HALF_OPEN) {
            $successes = (int) $this->cache->increment($this->key('successes'));
            if ($successes >= $this->successThreshold) {
                $this->transitionTo(CircuitState::CLOSED);
            }
            return;
        }

        // CLOSED: reset the failure count.
        $this->cache->forget($this->key('failures'));
    }

    public function recordFailure(?string $reason = null): void
    {
        if ($reason !== null) {
            $this->cache->put($this->key('last_error'),    $reason,                             $this->cacheTtl);
            $this->cache->put($this->key('last_error_at'), Carbon::now()->toIso8601String(),    $this->cacheTtl);
        }

        $state = $this->state();

        if ($state === CircuitState::HALF_OPEN) {
            $this->transitionTo(CircuitState::OPEN);
            return;
        }

        $failures = (int) $this->cache->increment($this->key('failures'));
        if ($failures >= $this->failureThreshold) {
            $this->transitionTo(CircuitState::OPEN);
        }
    }

    /**
     * Returns the last recorded failure for dashboards and health-check endpoints.
     * Returns null if no failure has been recorded (or if the TTL has expired).
     */
    public function lastError(): ?array
    {
        $message = $this->cache->get($this->key('last_error'));
        if ($message === null) {
            return null;
        }
        return [
            'message'     => $message,
            'recorded_at' => $this->cache->get($this->key('last_error_at')),
        ];
    }

    private function transitionTo(CircuitState $next): void
    {
        $ttl = $this->cacheTtl;
        $this->cache->put($this->key('state'), $next->value, $ttl);

        if ($next === CircuitState::OPEN) {
            $this->cache->put($this->key('opened_at'), Carbon::now()->timestamp, $ttl);
            $this->cache->forget($this->key('successes'));
        } elseif ($next === CircuitState::CLOSED) {
            $this->cache->forget($this->key('failures'));
            $this->cache->forget($this->key('successes'));
            $this->cache->forget($this->key('opened_at'));
        } elseif ($next === CircuitState::HALF_OPEN) {
            $this->cache->forget($this->key('successes'));
        }
    }

    private function key(string $suffix): string
    {
        return "circuit:{$this->name}:{$suffix}";
    }
}
