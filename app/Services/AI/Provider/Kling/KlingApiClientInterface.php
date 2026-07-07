<?php

namespace App\Services\AI\Provider\Kling;

use App\Services\AI\Provider\Kling\Dto\SubmitVideoRequest;
use App\Services\AI\Provider\Kling\Dto\SubmitVideoResponse;
use App\Services\AI\Provider\Kling\Dto\TaskStatusResponse;
use App\Services\AI\Provider\Kling\Dto\VideoArtifact;

interface KlingApiClientInterface
{
    public function submitVideoTask(SubmitVideoRequest $request): SubmitVideoResponse;
    public function getTaskStatus(string $taskId): TaskStatusResponse;
    public function downloadResult(string $taskId): VideoArtifact;
    public function cancelTask(string $taskId): void;
}
