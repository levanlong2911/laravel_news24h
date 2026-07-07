<?php

namespace Tests\Unit\AI\Provider\Kling;

use App\Services\AI\Provider\Kling\Dto\SubmitVideoRequest;
use App\Services\AI\Provider\Kling\KlingApiClient;
use App\Services\AI\Provider\Kling\KlingApiException;
use App\Services\AI\Provider\Kling\KlingVideoStatus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class KlingApiClientTest extends TestCase
{
    private KlingApiClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = new KlingApiClient(
            accessKeyId:     'test-key-id',
            accessKeySecret: 'test-key-secret',
            baseUrl:         'https://api.klingai.com',
            timeout:         10,
        );
    }

    // ── submitVideoTask ───────────────────────────────────────────────────────

    public function test_submit_video_task_posts_to_correct_endpoint(): void
    {
        Http::fake([
            'api.klingai.com/v1/videos/text2video' => Http::response([
                'code'       => 0,
                'request_id' => 'req-001',
                'data'       => ['task_id' => 'task-abc', 'task_status' => 'submitted'],
            ]),
        ]);

        $response = $this->client->submitVideoTask($this->makeRequest());

        Http::assertSent(fn ($r) => str_contains($r->url(), '/v1/videos/text2video') && $r->method() === 'POST');
        $this->assertSame('task-abc', $response->taskId);
        $this->assertSame(KlingVideoStatus::PENDING, $response->status);
    }

    public function test_submit_throws_kling_api_exception_on_api_error(): void
    {
        Http::fake([
            '*' => Http::response(['code' => 1002, 'message' => 'Invalid key', 'request_id' => 'r1'], 401),
        ]);

        $this->expectException(KlingApiException::class);
        $this->client->submitVideoTask($this->makeRequest());
    }

    // ── getTaskStatus ─────────────────────────────────────────────────────────

    public function test_get_task_status_calls_correct_endpoint(): void
    {
        Http::fake([
            'api.klingai.com/v1/videos/text2video/task-xyz' => Http::response([
                'code'       => 0,
                'request_id' => 'req-002',
                'data'       => ['task_id' => 'task-xyz', 'task_status' => 'processing'],
            ]),
        ]);

        $status = $this->client->getTaskStatus('task-xyz');

        Http::assertSent(fn ($r) => str_contains($r->url(), '/v1/videos/text2video/task-xyz'));
        $this->assertSame(KlingVideoStatus::PROCESSING, $status->status);
    }

    // ── downloadResult ────────────────────────────────────────────────────────

    public function test_download_result_returns_artifact_for_completed_task(): void
    {
        Http::fake([
            '*' => Http::response([
                'code'       => 0,
                'request_id' => 'req-003',
                'data'       => [
                    'task_id'     => 'task-done',
                    'task_status' => 'succeed',
                    'task_result' => [
                        'videos' => [['id' => 'v1', 'url' => 'https://cdn.example.com/v.mp4', 'duration' => '5']],
                    ],
                ],
            ]),
        ]);

        $artifact = $this->client->downloadResult('task-done');

        $this->assertSame('https://cdn.example.com/v.mp4', $artifact->videoUrl);
        $this->assertSame(5.0, $artifact->durationSeconds);
    }

    public function test_download_result_throws_for_non_completed_task(): void
    {
        Http::fake([
            '*' => Http::response([
                'code' => 0, 'request_id' => 'r',
                'data' => ['task_id' => 'task-processing', 'task_status' => 'processing'],
            ]),
        ]);

        $this->expectException(\LogicException::class);
        $this->client->downloadResult('task-processing');
    }

    // ── error handling ────────────────────────────────────────────────────────

    public function test_throws_on_http_5xx(): void
    {
        Http::fake(['*' => Http::response([], 500)]);

        $this->expectException(KlingApiException::class);
        $this->client->getTaskStatus('any-id');
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function makeRequest(): SubmitVideoRequest
    {
        return new SubmitVideoRequest(
            prompt:          'A cinematic villa pool shot',
            negativePrompt:  'blurry, text',
            model:           'kling-v1',
            mode:            'std',
            durationSeconds: 5,
            aspectRatio:     '16:9',
            cfgScale:        0.5,
        );
    }
}
