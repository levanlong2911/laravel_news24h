<?php

namespace Tests\Unit\AI\Provider\Circuit;

use App\Services\AI\Provider\Circuit\CircuitBreaker;
use App\Services\AI\Provider\Circuit\CircuitBreakerAwareProvider;
use App\Services\AI\Provider\Circuit\CircuitState;
use App\Services\AI\Provider\Circuit\ProviderUnavailableException;
use App\Services\AI\Provider\Dto\RenderArtifact;
use App\Services\AI\Provider\Dto\RenderStatusResult;
use App\Services\AI\Provider\Dto\RenderSubmitResult;
use App\Services\AI\Provider\Dto\RenderVideoRequest;
use App\Services\AI\Provider\RenderVideoProvider;
use App\Services\AI\Provider\RenderVideoStatus;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\TestCase;

final class CircuitBreakerAwareProviderTest extends TestCase
{
    private function makeBreaker(int $failures = 5): CircuitBreaker
    {
        return new CircuitBreaker('test', new Repository(new ArrayStore()), failureThreshold: $failures);
    }

    private function makeRequest(): RenderVideoRequest
    {
        return new RenderVideoRequest('prompt', '', 5, '16:9');
    }

    private function makeSubmitResult(): RenderSubmitResult
    {
        return new RenderSubmitResult('task-1', RenderVideoStatus::PENDING, 'req-1');
    }

    private function makeStatusResult(RenderVideoStatus $status = RenderVideoStatus::PROCESSING): RenderStatusResult
    {
        return new RenderStatusResult('task-1', $status, 'req-1', null, null, null, null);
    }

    public function test_submit_delegates_to_inner_and_records_success(): void
    {
        $inner = $this->createMock(RenderVideoProvider::class);
        $inner->method('providerId')->willReturn('test');
        $inner->method('submit')->willReturn($this->makeSubmitResult());

        $circuit  = $this->makeBreaker();
        $provider = new CircuitBreakerAwareProvider($inner, $circuit);
        $result   = $provider->submit($this->makeRequest());

        $this->assertSame('task-1', $result->taskId);
        $this->assertSame(CircuitState::CLOSED, $circuit->state());
    }

    public function test_submit_records_failure_and_rethrows_on_exception(): void
    {
        $inner = $this->createMock(RenderVideoProvider::class);
        $inner->method('providerId')->willReturn('test');
        $inner->method('submit')->willThrowException(new \RuntimeException('API error'));

        $circuit = $this->makeBreaker(failures: 1);
        $provider = new CircuitBreakerAwareProvider($inner, $circuit);

        $this->expectException(\RuntimeException::class);
        $provider->submit($this->makeRequest());

        $this->assertSame(CircuitState::OPEN, $circuit->state());
    }

    public function test_throws_provider_unavailable_when_circuit_is_open(): void
    {
        $inner = $this->createMock(RenderVideoProvider::class);
        $inner->method('providerId')->willReturn('test');

        $circuit = $this->makeBreaker(failures: 1);
        $circuit->recordFailure(); // open the circuit

        $provider = new CircuitBreakerAwareProvider($inner, $circuit);

        $this->expectException(ProviderUnavailableException::class);
        $provider->submit($this->makeRequest());
    }

    public function test_provider_unavailable_exception_carries_provider_name(): void
    {
        $inner = $this->createMock(RenderVideoProvider::class);
        $inner->method('providerId')->willReturn('kling');

        $circuit = $this->makeBreaker(failures: 1);
        $circuit->recordFailure();

        $provider = new CircuitBreakerAwareProvider($inner, $circuit);

        try {
            $provider->status('task-1');
            $this->fail('Expected ProviderUnavailableException');
        } catch (ProviderUnavailableException $e) {
            $this->assertSame('kling', $e->provider());
            $this->assertSame(CircuitState::OPEN, $e->circuitState());
            $this->assertGreaterThan(0, $e->releaseDelay());
        }
    }

    public function test_cancel_swallows_exceptions_without_opening_circuit(): void
    {
        $inner = $this->createMock(RenderVideoProvider::class);
        $inner->method('providerId')->willReturn('test');
        $inner->method('cancel')->willThrowException(new \RuntimeException('cancel failed'));

        $circuit = $this->makeBreaker(failures: 1);
        $provider = new CircuitBreakerAwareProvider($inner, $circuit);

        // Should not throw and should not open the circuit.
        $provider->cancel('task-1');
        $this->assertSame(CircuitState::CLOSED, $circuit->state());
    }

    public function test_next_poll_delay_delegates_without_circuit_check(): void
    {
        $inner = $this->createMock(RenderVideoProvider::class);
        $inner->method('providerId')->willReturn('test');
        $inner->method('nextPollDelay')->willReturn(42);

        $circuit = $this->makeBreaker(failures: 1);
        $circuit->recordFailure(); // open the circuit

        $provider = new CircuitBreakerAwareProvider($inner, $circuit);

        // Even with an open circuit, nextPollDelay must not throw.
        $this->assertSame(42, $provider->nextPollDelay(3, $this->makeStatusResult()));
    }

    public function test_provider_id_delegates_to_inner(): void
    {
        $inner = $this->createMock(RenderVideoProvider::class);
        $inner->method('providerId')->willReturn('runway');

        $circuit = $this->makeBreaker();
        $provider = new CircuitBreakerAwareProvider($inner, $circuit);

        $this->assertSame('runway', $provider->providerId());
    }
}
