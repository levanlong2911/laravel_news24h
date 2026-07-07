<?php

namespace Tests\Unit\AI\Provider\Circuit;

use App\Services\AI\Provider\Circuit\CircuitBreaker;
use App\Services\AI\Provider\Circuit\CircuitState;
use Carbon\Carbon;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\TestCase;

final class CircuitBreakerTest extends TestCase
{
    private function makeBreaker(int $failures = 3, int $reset = 60, int $successes = 2): CircuitBreaker
    {
        return new CircuitBreaker(
            'test',
            new Repository(new ArrayStore()),
            failureThreshold: $failures,
            resetTimeout:     $reset,
            successThreshold: $successes,
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(); // reset fake clock
        parent::tearDown();
    }

    public function test_starts_in_closed_state(): void
    {
        $this->assertSame(CircuitState::CLOSED, $this->makeBreaker()->state());
    }

    public function test_remains_closed_below_failure_threshold(): void
    {
        $cb = $this->makeBreaker(failures: 3);
        $cb->recordFailure();
        $cb->recordFailure();
        $this->assertSame(CircuitState::CLOSED, $cb->state());
    }

    public function test_opens_at_failure_threshold(): void
    {
        $cb = $this->makeBreaker(failures: 3);
        $cb->recordFailure();
        $cb->recordFailure();
        $cb->recordFailure();
        $this->assertSame(CircuitState::OPEN, $cb->state());
    }

    public function test_is_available_returns_false_when_open(): void
    {
        $cb = $this->makeBreaker(failures: 1);
        $cb->recordFailure();
        $this->assertFalse($cb->isAvailable());
    }

    public function test_open_transitions_to_half_open_after_reset_timeout(): void
    {
        Carbon::setTestNow(Carbon::now());
        $cb = $this->makeBreaker(failures: 1, reset: 60);
        $cb->recordFailure();
        $this->assertSame(CircuitState::OPEN, $cb->state());

        Carbon::setTestNow(Carbon::now()->addSeconds(61));
        $this->assertSame(CircuitState::HALF_OPEN, $cb->state());
        $this->assertTrue($cb->isAvailable());
    }

    public function test_half_open_closes_after_success_threshold(): void
    {
        Carbon::setTestNow(Carbon::now());
        $cb = $this->makeBreaker(failures: 1, reset: 60, successes: 2);
        $cb->recordFailure();

        Carbon::setTestNow(Carbon::now()->addSeconds(61));
        $cb->state(); // trigger OPEN → HALF_OPEN transition

        $cb->recordSuccess();
        $this->assertSame(CircuitState::HALF_OPEN, $cb->state());
        $cb->recordSuccess();
        $this->assertSame(CircuitState::CLOSED, $cb->state());
    }

    public function test_half_open_reopens_on_failure(): void
    {
        Carbon::setTestNow(Carbon::now());
        $cb = $this->makeBreaker(failures: 1, reset: 60);
        $cb->recordFailure();

        Carbon::setTestNow(Carbon::now()->addSeconds(61));
        $cb->state(); // trigger transition

        $cb->recordFailure(); // probe failed → back to OPEN
        $this->assertSame(CircuitState::OPEN, $cb->state());
    }

    public function test_success_in_closed_resets_failure_count(): void
    {
        $cb = $this->makeBreaker(failures: 3);
        $cb->recordFailure();
        $cb->recordFailure();
        $cb->recordSuccess(); // resets counter

        $cb->recordFailure();
        $cb->recordFailure();
        // Only 2 failures since last reset — still CLOSED.
        $this->assertSame(CircuitState::CLOSED, $cb->state());
    }

    public function test_last_error_is_null_before_any_failure(): void
    {
        $this->assertNull($this->makeBreaker()->lastError());
    }

    public function test_last_error_stores_reason_and_timestamp(): void
    {
        Carbon::setTestNow(Carbon::now());
        $cb = $this->makeBreaker();
        $cb->recordFailure('HTTP 429 Too Many Requests');

        $err = $cb->lastError();
        $this->assertNotNull($err);
        $this->assertSame('HTTP 429 Too Many Requests', $err['message']);
        $this->assertNotNull($err['recorded_at']);
    }

    public function test_last_error_updates_on_subsequent_failures(): void
    {
        $cb = $this->makeBreaker(failures: 5);
        $cb->recordFailure('connection timeout');
        $cb->recordFailure('HTTP 500');

        $this->assertSame('HTTP 500', $cb->lastError()['message']);
    }

    public function test_last_error_persists_after_circuit_opens(): void
    {
        $cb = $this->makeBreaker(failures: 1);
        $cb->recordFailure('DNS failure');

        $this->assertSame(CircuitState::OPEN, $cb->state());
        $this->assertSame('DNS failure', $cb->lastError()['message']);
    }

    public function test_closed_after_success_threshold_from_half_open_resets_counters(): void
    {
        Carbon::setTestNow(Carbon::now());
        $cb = $this->makeBreaker(failures: 1, reset: 60, successes: 1);
        $cb->recordFailure();

        Carbon::setTestNow(Carbon::now()->addSeconds(61));
        $cb->state();

        $cb->recordSuccess(); // closes
        $this->assertSame(CircuitState::CLOSED, $cb->state());

        // Failure counter is reset — needs failures >= threshold to reopen.
        $cb->recordFailure();
        $this->assertSame(CircuitState::OPEN, $cb->state());
    }
}
