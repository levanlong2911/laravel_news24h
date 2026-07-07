<?php

namespace App\Services\AI\Provider\Kling;

use App\Services\AI\Provider\Kling\Dto\ApiError;
use App\Services\AI\Provider\Kling\Dto\SubmitVideoResponse;
use App\Services\AI\Provider\Kling\Dto\TaskStatusResponse;
use App\Services\AI\Provider\Kling\Dto\VideoArtifact;

/**
 * Maps raw Kling API response arrays to typed DTOs.
 * Knows Kling's response schema; knows nothing about HTTP or business logic.
 */
final class KlingResponseMapper
{
    /**
     * @param array<string, mixed> $body
     */
    public function toSubmitVideoResponse(array $body): SubmitVideoResponse
    {
        $data = $body['data'] ?? [];

        return new SubmitVideoResponse(
            taskId:    (string) ($data['task_id'] ?? ''),
            status:    $this->parseStatus($data['task_status'] ?? ''),
            requestId: (string) ($body['request_id'] ?? ''),
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    public function toTaskStatusResponse(array $body): TaskStatusResponse
    {
        $data    = $body['data'] ?? [];
        $result  = $data['task_result'] ?? [];
        $video   = ($result['videos'] ?? [])[0] ?? null;
        $errMsg  = ($data['task_status_msg'] ?? '') ?: null;

        return new TaskStatusResponse(
            taskId:          (string) ($data['task_id'] ?? ''),
            status:          $this->parseStatus($data['task_status'] ?? ''),
            requestId:       (string) ($body['request_id'] ?? ''),
            videoUrl:        $video !== null ? (string) $video['url'] : null,
            thumbnailUrl:    $video !== null ? ($video['thumbnail_url'] ?? null) : null,
            errorMessage:    $errMsg,
            durationSeconds: $video !== null ? (float) ($video['duration'] ?? 0) : null,
        );
    }

    public function toVideoArtifact(TaskStatusResponse $status): VideoArtifact
    {
        if ($status->videoUrl === null) {
            throw new \LogicException("No video URL available for task {$status->taskId}.");
        }

        return new VideoArtifact(
            taskId:          $status->taskId,
            videoUrl:        $status->videoUrl,
            thumbnailUrl:    $status->thumbnailUrl,
            durationSeconds: $status->durationSeconds ?? 0.0,
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    public function toApiError(array $body, int $httpStatus): ApiError
    {
        return new ApiError(
            code:       (int) ($body['code'] ?? $httpStatus),
            message:    (string) ($body['message'] ?? 'Unknown error'),
            requestId:  (string) ($body['request_id'] ?? ''),
            httpStatus: $httpStatus,
        );
    }

    private function parseStatus(string $raw): KlingVideoStatus
    {
        return KlingVideoStatus::tryFrom($raw) ?? KlingVideoStatus::PENDING;
    }
}
