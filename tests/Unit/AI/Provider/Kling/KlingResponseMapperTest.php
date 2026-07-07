<?php

namespace Tests\Unit\AI\Provider\Kling;

use App\Services\AI\Provider\Kling\Dto\TaskStatusResponse;
use App\Services\AI\Provider\Kling\KlingResponseMapper;
use App\Services\AI\Provider\Kling\KlingVideoStatus;
use PHPUnit\Framework\TestCase;

final class KlingResponseMapperTest extends TestCase
{
    private KlingResponseMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new KlingResponseMapper();
    }

    // ── toSubmitVideoResponse ─────────────────────────────────────────────────

    public function test_maps_submit_response(): void
    {
        $body = [
            'code'       => 0,
            'message'    => 'OK',
            'request_id' => 'req-abc',
            'data'       => [
                'task_id'     => 'task-xyz',
                'task_status' => 'submitted',
            ],
        ];

        $result = $this->mapper->toSubmitVideoResponse($body);

        $this->assertSame('task-xyz', $result->taskId);
        $this->assertSame(KlingVideoStatus::PENDING, $result->status);
        $this->assertSame('req-abc', $result->requestId);
    }

    // ── toTaskStatusResponse ──────────────────────────────────────────────────

    public function test_maps_completed_status_with_video(): void
    {
        $body = [
            'code'       => 0,
            'request_id' => 'req-def',
            'data'       => [
                'task_id'         => 'task-xyz',
                'task_status'     => 'succeed',
                'task_status_msg' => '',
                'task_result'     => [
                    'videos' => [[
                        'id'       => 'vid-001',
                        'url'      => 'https://cdn.example.com/video.mp4',
                        'duration' => '5',
                    ]],
                ],
            ],
        ];

        $result = $this->mapper->toTaskStatusResponse($body);

        $this->assertSame(KlingVideoStatus::COMPLETED, $result->status);
        $this->assertSame('https://cdn.example.com/video.mp4', $result->videoUrl);
        $this->assertSame(5.0, $result->durationSeconds);
        $this->assertNull($result->errorMessage);
    }

    public function test_maps_processing_status_without_video(): void
    {
        $body = [
            'code'       => 0,
            'request_id' => 'req-xyz',
            'data'       => [
                'task_id'     => 'task-abc',
                'task_status' => 'processing',
            ],
        ];

        $result = $this->mapper->toTaskStatusResponse($body);

        $this->assertSame(KlingVideoStatus::PROCESSING, $result->status);
        $this->assertNull($result->videoUrl);
        $this->assertNull($result->durationSeconds);
    }

    public function test_maps_failed_status_with_error_message(): void
    {
        $body = [
            'code'       => 0,
            'request_id' => 'req-fail',
            'data'       => [
                'task_id'         => 'task-fail',
                'task_status'     => 'failed',
                'task_status_msg' => 'Content policy violation',
            ],
        ];

        $result = $this->mapper->toTaskStatusResponse($body);

        $this->assertSame(KlingVideoStatus::FAILED, $result->status);
        $this->assertSame('Content policy violation', $result->errorMessage);
    }

    public function test_unknown_status_defaults_to_pending(): void
    {
        $body = ['code' => 0, 'request_id' => '', 'data' => ['task_id' => 't', 'task_status' => 'unknown_future_value']];
        $result = $this->mapper->toTaskStatusResponse($body);
        $this->assertSame(KlingVideoStatus::PENDING, $result->status);
    }

    // ── toVideoArtifact ───────────────────────────────────────────────────────

    public function test_maps_video_artifact_from_completed_status(): void
    {
        $status = new TaskStatusResponse(
            taskId: 'task-xyz', status: KlingVideoStatus::COMPLETED, requestId: 'req-1',
            videoUrl: 'https://cdn.example.com/video.mp4', thumbnailUrl: 'https://cdn.example.com/thumb.jpg',
            errorMessage: null, durationSeconds: 5.0,
        );

        $artifact = $this->mapper->toVideoArtifact($status);

        $this->assertSame('task-xyz', $artifact->taskId);
        $this->assertSame('https://cdn.example.com/video.mp4', $artifact->videoUrl);
        $this->assertSame(5.0, $artifact->durationSeconds);
    }

    public function test_to_video_artifact_throws_when_no_url(): void
    {
        $status = new TaskStatusResponse(
            taskId: 'task-abc', status: KlingVideoStatus::COMPLETED, requestId: 'req-1',
            videoUrl: null, thumbnailUrl: null, errorMessage: null, durationSeconds: null,
        );

        $this->expectException(\LogicException::class);
        $this->mapper->toVideoArtifact($status);
    }

    // ── toApiError ────────────────────────────────────────────────────────────

    public function test_maps_api_error(): void
    {
        $body = ['code' => 1002, 'message' => 'Unauthorized', 'request_id' => 'req-err'];
        $error = $this->mapper->toApiError($body, 401);

        $this->assertSame(1002, $error->code);
        $this->assertSame('Unauthorized', $error->message);
        $this->assertSame(401, $error->httpStatus);
        $this->assertStringContainsString('Unauthorized', (string) $error);
    }

    public function test_api_error_falls_back_to_http_status_code_when_body_empty(): void
    {
        $error = $this->mapper->toApiError([], 503);
        $this->assertSame(503, $error->code);
        $this->assertSame('Unknown error', $error->message);
    }
}
