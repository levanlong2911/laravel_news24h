<?php

namespace Tests\Unit\AI\Provider\Kling;

use App\Services\AI\Provider\Dto\RenderVideoRequest;
use App\Services\AI\Provider\Kling\KlingApiClientInterface;
use App\Services\AI\Provider\Kling\KlingVideoProvider;
use App\Services\AI\Provider\Kling\KlingVideoStatus;
use App\Services\AI\Provider\Kling\Dto\SubmitVideoResponse;
use App\Services\AI\Provider\Kling\Dto\TaskStatusResponse;
use App\Services\AI\Provider\Kling\Dto\VideoArtifact;
use App\Services\AI\Provider\RenderVideoStatus;
use PHPUnit\Framework\TestCase;

final class KlingVideoProviderTest extends TestCase
{
    private function makeProvider(KlingApiClientInterface $client): KlingVideoProvider
    {
        return new KlingVideoProvider(
            client:   $client,
            model:    'kling-v1',
            mode:     'std',
            cfgScale: 0.5,
        );
    }

    private function makeRequest(): RenderVideoRequest
    {
        return new RenderVideoRequest(
            prompt:          'A cinematic villa shot',
            negativePrompt:  'blurry',
            durationSeconds: 5,
            aspectRatio:     '16:9',
        );
    }

    public function test_provider_id_is_kling(): void
    {
        $client   = $this->createMock(KlingApiClientInterface::class);
        $provider = $this->makeProvider($client);
        $this->assertSame('kling', $provider->providerId());
    }

    public function test_submit_maps_pending_status(): void
    {
        $client = $this->createMock(KlingApiClientInterface::class);
        $client->method('submitVideoTask')->willReturn(
            new SubmitVideoResponse('task-abc', KlingVideoStatus::PENDING, 'req-1')
        );

        $result = $this->makeProvider($client)->submit($this->makeRequest());

        $this->assertSame('task-abc', $result->taskId);
        $this->assertSame(RenderVideoStatus::PENDING, $result->status);
    }

    public function test_status_maps_processing(): void
    {
        $client = $this->createMock(KlingApiClientInterface::class);
        $client->method('getTaskStatus')->willReturn(
            new TaskStatusResponse('task-abc', KlingVideoStatus::PROCESSING, 'req-2', null, null, null, null)
        );

        $result = $this->makeProvider($client)->status('task-abc');

        $this->assertSame(RenderVideoStatus::PROCESSING, $result->status);
        $this->assertNull($result->videoUrl);
    }

    public function test_status_maps_completed_with_video_url(): void
    {
        $client = $this->createMock(KlingApiClientInterface::class);
        $client->method('getTaskStatus')->willReturn(
            new TaskStatusResponse(
                'task-abc', KlingVideoStatus::COMPLETED, 'req-3',
                'https://cdn.example.com/v.mp4', null, null, 5.0,
            )
        );

        $result = $this->makeProvider($client)->status('task-abc');

        $this->assertSame(RenderVideoStatus::COMPLETED, $result->status);
        $this->assertSame('https://cdn.example.com/v.mp4', $result->videoUrl);
    }

    public function test_artifact_wraps_kling_artifact(): void
    {
        $client = $this->createMock(KlingApiClientInterface::class);
        $client->method('downloadResult')->willReturn(
            new VideoArtifact('task-abc', 'https://cdn.example.com/v.mp4', null, 5.0)
        );

        $artifact = $this->makeProvider($client)->artifact('task-abc');

        $this->assertSame('task-abc', $artifact->taskId);
        $this->assertSame('https://cdn.example.com/v.mp4', $artifact->videoUrl);
        $this->assertSame(5.0, $artifact->durationSeconds);
    }

    /**
     * @dataProvider klingStatusMappingProvider
     */
    public function test_kling_status_maps_to_render_status(KlingVideoStatus $kling, RenderVideoStatus $expected): void
    {
        $client = $this->createMock(KlingApiClientInterface::class);
        $client->method('getTaskStatus')->willReturn(
            new TaskStatusResponse('t', $kling, 'r', null, null, null, null)
        );

        $result = $this->makeProvider($client)->status('t');

        $this->assertSame(
            $expected,
            $result->status,
            "KlingVideoStatus::{$kling->name} should map to RenderVideoStatus::{$expected->name}",
        );
    }

    public static function klingStatusMappingProvider(): array
    {
        return [
            'pending'    => [KlingVideoStatus::PENDING,    RenderVideoStatus::PENDING],
            'processing' => [KlingVideoStatus::PROCESSING, RenderVideoStatus::PROCESSING],
            'completed'  => [KlingVideoStatus::COMPLETED,  RenderVideoStatus::COMPLETED],
            'failed'     => [KlingVideoStatus::FAILED,     RenderVideoStatus::FAILED],
        ];
    }

    public function test_next_poll_delay_caps_at_300_seconds(): void
    {
        $client   = $this->createMock(KlingApiClientInterface::class);
        $provider = $this->makeProvider($client);
        $status   = new \App\Services\AI\Provider\Dto\RenderStatusResult(
            't', RenderVideoStatus::PROCESSING, 'r', null, null, null, null
        );

        $this->assertSame(15,  $provider->nextPollDelay(0, $status));
        $this->assertSame(30,  $provider->nextPollDelay(1, $status));
        $this->assertSame(60,  $provider->nextPollDelay(2, $status));
        $this->assertSame(300, $provider->nextPollDelay(6, $status));
        $this->assertSame(300, $provider->nextPollDelay(99, $status));
    }
}
