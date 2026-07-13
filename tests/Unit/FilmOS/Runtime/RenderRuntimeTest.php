<?php

declare(strict_types=1);

namespace Tests\Unit\FilmOS\Runtime;

use App\Services\AI\FilmOS\FilmOSError;
use App\Services\AI\FilmOS\Render\ProviderCapability;
use App\Services\AI\FilmOS\Render\ProviderSerializer;
use App\Services\AI\FilmOS\Render\RenderIR;
use App\Services\AI\FilmOS\Runtime\ProviderClient;
use App\Services\AI\FilmOS\Runtime\ProviderResult;
use App\Services\AI\FilmOS\Runtime\RenderRuntime;
use App\Services\AI\FilmOS\Runtime\RetryPolicy;
use App\Services\AI\FilmOS\Runtime\RuntimeEvent;
use PHPUnit\Framework\TestCase;

final class RenderRuntimeTest extends TestCase
{
    private const TRACE_ID   = 'trace-test-001';
    private const REQUEST_ID = 'task-abc';
    private const ASSET_URL  = 'https://cdn.example.com/video.mp4';

    private ProviderSerializer $serializer;
    private ProviderClient     $client;
    private RetryPolicy        $noSleepPolicy;
    private RenderIR           $renderIr;

    protected function setUp(): void
    {
        $this->serializer    = $this->createMock(ProviderSerializer::class);
        $this->client        = $this->createMock(ProviderClient::class);
        $this->noSleepPolicy = new RetryPolicy(maxAttempts: 5, backoff: 0.0);
        $this->renderIr      = $this->makeRenderIr();

        $this->serializer->method('provider')->willReturn('kling');
        $this->serializer->method('capability')->willReturn(
            new ProviderCapability(maxDurationSeconds: 10)
        );
    }

    // ── Happy path ────────────────────────────────────────────────────────────

    /** @test */
    public function happy_path_submit_poll_download_returns_download_completed(): void
    {
        $this->serializer->method('supports')->willReturn(true);
        $this->serializer->method('serialize')->willReturn(['prompt' => 'test']);

        $this->client->expects($this->once())->method('submit')
            ->with(self::TRACE_ID, ['prompt' => 'test'])
            ->willReturn($this->providerResult(RuntimeEvent::SUBMITTED));

        $this->client->expects($this->exactly(2))->method('poll')
            ->willReturnOnConsecutiveCalls(
                $this->providerResult(RuntimeEvent::POLLING),
                $this->providerResult(RuntimeEvent::COMPLETED),
            );

        $this->client->expects($this->once())->method('download')
            ->willReturn($this->providerResult(RuntimeEvent::DOWNLOAD_COMPLETED, self::ASSET_URL, 5.0));

        $result = $this->runtime()->run($this->renderIr);

        $this->assertSame(RuntimeEvent::DOWNLOAD_COMPLETED, $result->status);
        $this->assertSame(self::ASSET_URL, $result->assetUrl);
        $this->assertSame(5.0, $result->duration);
    }

    /** @test */
    public function immediately_completed_result_skips_poll_loop(): void
    {
        $this->serializer->method('supports')->willReturn(true);
        $this->serializer->method('serialize')->willReturn([]);

        $this->client->method('submit')
            ->willReturn($this->providerResult(RuntimeEvent::COMPLETED));

        $this->client->expects($this->never())->method('poll');

        $this->client->expects($this->once())->method('download')
            ->willReturn($this->providerResult(RuntimeEvent::DOWNLOAD_COMPLETED, self::ASSET_URL));

        $result = $this->runtime()->run($this->renderIr);

        $this->assertSame(RuntimeEvent::DOWNLOAD_COMPLETED, $result->status);
    }

    // ── Failure paths ─────────────────────────────────────────────────────────

    /** @test */
    public function provider_not_supported_throws_filmos_error(): void
    {
        $this->serializer->method('supports')->willReturn(false);

        $this->expectException(FilmOSError::class);
        $this->expectExceptionMessage("Provider 'kling' does not support");

        $this->runtime()->run($this->renderIr);
    }

    /** @test */
    public function failed_status_stops_loop_without_download(): void
    {
        $this->serializer->method('supports')->willReturn(true);
        $this->serializer->method('serialize')->willReturn([]);

        $this->client->method('submit')->willReturn($this->providerResult(RuntimeEvent::SUBMITTED));
        $this->client->method('poll')->willReturn($this->providerResult(RuntimeEvent::FAILED));
        $this->client->expects($this->never())->method('download');

        $result = $this->runtime()->run($this->renderIr);

        $this->assertSame(RuntimeEvent::FAILED, $result->status);
    }

    /** @test */
    public function timeout_when_max_poll_attempts_exceeded(): void
    {
        $this->serializer->method('supports')->willReturn(true);
        $this->serializer->method('serialize')->willReturn([]);

        $this->client->method('submit')->willReturn($this->providerResult(RuntimeEvent::SUBMITTED));
        $this->client->method('poll')->willReturn($this->providerResult(RuntimeEvent::POLLING));
        $this->client->expects($this->never())->method('download');

        $policy = new RetryPolicy(maxAttempts: 2, backoff: 0.0);
        $result = $this->runtime($policy)->run($this->renderIr);

        $this->assertSame(RuntimeEvent::TIMEOUT, $result->status);
        $this->assertSame(2, $result->metadata['pollCount']);
    }

    // ── traceId contract ──────────────────────────────────────────────────────

    /** @test */
    public function trace_id_is_preserved_through_full_workflow(): void
    {
        $this->serializer->method('supports')->willReturn(true);
        $this->serializer->method('serialize')->willReturn([]);

        $this->client->method('submit')->willReturn($this->providerResult(RuntimeEvent::COMPLETED));
        $this->client->method('download')
            ->willReturn($this->providerResult(RuntimeEvent::DOWNLOAD_COMPLETED, self::ASSET_URL));

        $result = $this->runtime()->run($this->renderIr);

        $this->assertSame(self::TRACE_ID, $result->traceId);
    }

    // ── RetryPolicy contract ──────────────────────────────────────────────────

    /** @test */
    public function retry_policy_without_sleep_executes_instantly(): void
    {
        $this->serializer->method('supports')->willReturn(true);
        $this->serializer->method('serialize')->willReturn([]);

        $this->client->method('submit')->willReturn($this->providerResult(RuntimeEvent::SUBMITTED));
        $this->client->method('poll')->willReturnOnConsecutiveCalls(
            $this->providerResult(RuntimeEvent::POLLING),
            $this->providerResult(RuntimeEvent::POLLING),
            $this->providerResult(RuntimeEvent::COMPLETED),
        );
        $this->client->method('download')
            ->willReturn($this->providerResult(RuntimeEvent::DOWNLOAD_COMPLETED, self::ASSET_URL));

        $start  = microtime(true);
        $this->runtime()->run($this->renderIr);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(0.5, $elapsed, 'backoff: 0.0 must not introduce real sleep');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function runtime(?RetryPolicy $policy = null): RenderRuntime
    {
        return new RenderRuntime($this->serializer, $this->client, $policy ?? $this->noSleepPolicy);
    }

    private function providerResult(
        RuntimeEvent $status,
        string       $assetUrl = '',
        float        $duration = 0.0,
    ): ProviderResult {
        return new ProviderResult(
            traceId:   self::TRACE_ID,
            provider:  'kling',
            requestId: self::REQUEST_ID,
            status:    $status,
            assetUrl:  $assetUrl,
            duration:  $duration,
        );
    }

    private function makeRenderIr(): RenderIR
    {
        return new RenderIR(
            traceId:            self::TRACE_ID,
            version:            1,
            shotId:             'hook_1',
            durationSeconds:    5,
            renderInstructions: ['description' => 'test shot'],
            constraints:        ['duration' => 5],
            metadata:           [],
        );
    }
}
