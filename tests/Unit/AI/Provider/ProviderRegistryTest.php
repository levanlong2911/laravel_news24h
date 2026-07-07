<?php

namespace Tests\Unit\AI\Provider;

use App\Services\AI\Provider\Dto\RenderArtifact;
use App\Services\AI\Provider\Dto\RenderStatusResult;
use App\Services\AI\Provider\Dto\RenderSubmitResult;
use App\Services\AI\Provider\Dto\RenderVideoRequest;
use App\Services\AI\Provider\ProviderRegistry;
use App\Services\AI\Provider\RenderVideoProvider;
use App\Services\AI\Provider\RenderVideoStatus;
use PHPUnit\Framework\TestCase;

final class ProviderRegistryTest extends TestCase
{
    public function test_make_returns_registered_provider(): void
    {
        $registry = new ProviderRegistry();
        $provider = $this->makeStubProvider('test-provider');
        $registry->register('test', fn () => $provider);

        $this->assertSame($provider, $registry->make('test'));
    }

    public function test_has_returns_true_for_registered_name(): void
    {
        $registry = new ProviderRegistry();
        $registry->register('kling', fn () => $this->makeStubProvider('kling'));

        $this->assertTrue($registry->has('kling'));
        $this->assertFalse($registry->has('veo'));
    }

    public function test_registered_lists_all_names(): void
    {
        $registry = new ProviderRegistry();
        $registry->register('kling', fn () => $this->makeStubProvider('kling'));
        $registry->register('veo',   fn () => $this->makeStubProvider('veo'));

        $this->assertSame(['kling', 'veo'], $registry->registered());
    }

    public function test_make_throws_for_unknown_provider(): void
    {
        $registry = new ProviderRegistry();
        $registry->register('kling', fn () => $this->makeStubProvider('kling'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("'veo'");
        $registry->make('veo');
    }

    public function test_error_message_lists_available_providers(): void
    {
        $registry = new ProviderRegistry();
        $registry->register('kling', fn () => $this->makeStubProvider('kling'));

        try {
            $registry->make('unknown');
            $this->fail('Expected exception not thrown');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('kling', $e->getMessage());
        }
    }

    public function test_factory_is_called_each_time_make_is_called(): void
    {
        $callCount = 0;
        $registry  = new ProviderRegistry();
        $registry->register('test', function () use (&$callCount) {
            $callCount++;
            return $this->makeStubProvider('test');
        });

        $registry->make('test');
        $registry->make('test');

        $this->assertSame(2, $callCount);
    }

    private function makeStubProvider(string $id): RenderVideoProvider
    {
        return new class ($id) implements RenderVideoProvider {
            public function __construct(private string $id) {}
            public function providerId(): string { return $this->id; }
            public function submit(RenderVideoRequest $r): RenderSubmitResult
            {
                return new RenderSubmitResult('t', RenderVideoStatus::PENDING, 'r');
            }
            public function status(string $taskId): RenderStatusResult
            {
                return new RenderStatusResult($taskId, RenderVideoStatus::PROCESSING, '', null, null, null, null);
            }
            public function artifact(string $taskId): RenderArtifact
            {
                return new RenderArtifact($taskId, 'https://example.com/v.mp4', null, 5.0);
            }
            public function cancel(string $taskId): void {}
            public function nextPollDelay(int $attempt, RenderStatusResult $status): int { return 15; }
        };
    }
}
