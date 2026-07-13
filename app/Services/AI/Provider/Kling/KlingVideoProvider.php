<?php

namespace App\Services\AI\Provider\Kling;

use App\Services\AI\Provider\Dto\RenderArtifact;
use App\Services\AI\Provider\Dto\RenderStatusResult;
use App\Services\AI\Provider\Dto\RenderSubmitResult;
use App\Services\AI\Provider\Dto\RenderVideoRequest;
use App\Services\AI\Provider\Kling\Dto\SubmitVideoRequest;
use App\Services\AI\Provider\RenderVideoProvider;
use App\Services\AI\Provider\RenderVideoStatus;

/**
 * Adapter: translates the provider-agnostic RenderVideoProvider interface
 * into Kling-specific API calls via KlingApiClient.
 *
 * Pipeline code imports only RenderVideoProvider. Adding Veo or Runway = new adapter class.
 */
final class KlingVideoProvider implements RenderVideoProvider
{
    public function __construct(
        private readonly KlingApiClientInterface $client,
        private readonly string         $model,
        private readonly string         $mode,
        private readonly float          $cfgScale,
    ) {}

    public static function fromConfig(): self
    {
        // Prefer FAL proxy when FAL_API_KEY is set; fall back to direct JWT client.
        $client = config('kling.fal_api_key')
            ? FalKlingApiClient::fromConfig()
            : KlingApiClient::fromConfig();

        return new self(
            client:   $client,
            model:    (string) config('kling.default_model', 'kling-v1'),
            mode:     (string) config('kling.default_mode', 'std'),
            cfgScale: (float)  config('kling.cfg_scale', 0.5),
        );
    }

    public function providerId(): string
    {
        return 'kling';
    }

    public function submit(RenderVideoRequest $request): RenderSubmitResult
    {
        $klingRequest = new SubmitVideoRequest(
            prompt:          $request->prompt,
            negativePrompt:  $request->negativePrompt,
            model:           $this->model,
            mode:            $this->mode,
            durationSeconds: $request->durationSeconds,
            aspectRatio:     $request->aspectRatio,
            cfgScale:        $this->cfgScale,
        );

        $response = $this->client->submitVideoTask($klingRequest);

        return new RenderSubmitResult(
            taskId:    $response->taskId,
            status:    $this->mapStatus($response->status),
            requestId: $response->requestId,
        );
    }

    public function status(string $taskId): RenderStatusResult
    {
        $response = $this->client->getTaskStatus($taskId);

        return new RenderStatusResult(
            taskId:          $response->taskId,
            status:          $this->mapStatus($response->status),
            requestId:       $response->requestId,
            videoUrl:        $response->videoUrl,
            thumbnailUrl:    $response->thumbnailUrl,
            errorMessage:    $response->errorMessage,
            durationSeconds: $response->durationSeconds,
        );
    }

    public function artifact(string $taskId): RenderArtifact
    {
        $klingArtifact = $this->client->downloadResult($taskId);

        return new RenderArtifact(
            taskId:          $klingArtifact->taskId,
            videoUrl:        $klingArtifact->videoUrl,
            thumbnailUrl:    $klingArtifact->thumbnailUrl,
            durationSeconds: $klingArtifact->durationSeconds,
        );
    }

    public function cancel(string $taskId): void
    {
        $this->client->cancelTask($taskId);
    }

    /**
     * Kling typical render: 3–10 minutes.
     * Backoff: 15 → 30 → 60 → 90 → 120 → 180 → 300s (cap at 5 min).
     */
    public function nextPollDelay(int $attempt, RenderStatusResult $status): int
    {
        static $table = [15, 30, 60, 90, 120, 180, 300];
        return $table[min($attempt, count($table) - 1)];
    }

    private function mapStatus(KlingVideoStatus $status): RenderVideoStatus
    {
        return match ($status) {
            KlingVideoStatus::PENDING    => RenderVideoStatus::PENDING,
            KlingVideoStatus::PROCESSING => RenderVideoStatus::PROCESSING,
            KlingVideoStatus::COMPLETED  => RenderVideoStatus::COMPLETED,
            KlingVideoStatus::FAILED     => RenderVideoStatus::FAILED,
        };
    }
}
