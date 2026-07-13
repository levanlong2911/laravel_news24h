<?php

declare(strict_types=1);

namespace App\Services\AI\Provider\Kling;

use App\Services\AI\Provider\Kling\Dto\SubmitVideoRequest;
use App\Services\AI\Provider\Kling\Dto\SubmitVideoResponse;
use App\Services\AI\Provider\Kling\Dto\TaskStatusResponse;
use App\Services\AI\Provider\Kling\Dto\VideoArtifact;
use Illuminate\Support\Facades\Http;

/**
 * Kling text-to-video via fal.ai queue.
 *
 * Auth:     Authorization: Key {FAL_KEY}
 * Endpoint: https://queue.fal.run/fal-ai/kling-video/{model}/text-to-video
 *
 * Flow: submit → poll status → fetch result video URL.
 * FAL async queue maps cleanly to KlingApiClientInterface:
 *   submitVideoTask → POST (returns request_id as taskId, PENDING)
 *   getTaskStatus   → GET status endpoint (IN_QUEUE/IN_PROGRESS → PROCESSING, COMPLETED, FAILED)
 *   downloadResult  → fetchVideoUrl from COMPLETED status body
 */
final class FalKlingApiClient implements KlingApiClientInterface
{
    private const QUEUE_BASE = 'https://queue.fal.run/fal-ai/kling-video';

    /** @var array<string, string> FAL-provided status URLs (no model-version segment) */
    private array $statusUrls = [];

    /** @var array<string, string> FAL-provided result URLs (route-based, no model-version segment) */
    private array $resultUrls = [];

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,   // e.g. 'v1.6/standard', 'v1.6/pro'
        private readonly int    $timeout = 30,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            apiKey:  (string) config('kling.fal_api_key'),
            model:   (string) config('kling.fal_model', 'v1.6/standard'),
            timeout: (int)    config('kling.timeout', 30),
        );
    }

    // ── Public API ────────────────────────────────────────────────────────────

    public function submitVideoTask(SubmitVideoRequest $request): SubmitVideoResponse
    {
        $response = Http::withHeaders($this->headers())
            ->timeout($this->timeout)
            ->post($this->endpointUrl(), [
                'prompt'       => $request->prompt,
                'duration'     => (string) $request->durationSeconds,
                'aspect_ratio' => $request->aspectRatio,
            ]);

        $this->assertOk($response, 'submit');

        $data      = $response->json();
        $requestId = $data['request_id'] ?? '';

        if ($requestId === '') {
            throw new KlingApiException(
                new Dto\ApiError(code: -1, message: 'FAL submit returned no request_id', requestId: '', httpStatus: 200)
            );
        }

        // Use FAL-provided URLs directly — they omit the model-version segment
        // but the base queue endpoint handles routing correctly for valid models.
        // (Constructing URLs manually with the model path produces HTTP 405.)
        $this->statusUrls[$requestId] = $data['status_url']
            ?? (self::QUEUE_BASE . "/requests/{$requestId}/status");
        $this->resultUrls[$requestId] = $data['response_url']
            ?? (self::QUEUE_BASE . "/requests/{$requestId}");

        return new SubmitVideoResponse(
            taskId:    $requestId,
            status:    KlingVideoStatus::PENDING,
            requestId: $requestId,
        );
    }

    public function getTaskStatus(string $taskId): TaskStatusResponse
    {
        // Prefer FAL-provided status_url (stored at submit time); fall back to constructed URL.
        $statusUrl = $this->statusUrls[$taskId]
            ?? ($this->endpointUrl() . "/requests/{$taskId}/status");

        $response = Http::withHeaders($this->headers())
            ->timeout($this->timeout)
            ->get($statusUrl);

        $this->assertOk($response, "status/{$taskId}");

        $body   = $response->json();
        $status = strtoupper((string) ($body['status'] ?? ''));

        $klingStatus = match (true) {
            $status === 'COMPLETED'                         => KlingVideoStatus::COMPLETED,
            in_array($status, ['FAILED', 'CANCELLED'], true) => KlingVideoStatus::FAILED,
            default                                         => KlingVideoStatus::PROCESSING,
        };

        $videoUrl = null;
        if ($klingStatus === KlingVideoStatus::COMPLETED) {
            // Status body rarely contains the video URL; fetch from result endpoint.
            $videoUrl = $this->extractVideoUrlFromBody($body)
                ?? $this->fetchVideoUrl($taskId);
        }

        return new TaskStatusResponse(
            taskId:          $taskId,
            status:          $klingStatus,
            requestId:       $taskId,
            videoUrl:        $videoUrl,
            thumbnailUrl:    null,
            errorMessage:    $klingStatus === KlingVideoStatus::FAILED
                                 ? ($body['error'] ?? 'FAL job failed')
                                 : null,
            durationSeconds: null,
        );
    }

    public function downloadResult(string $taskId): VideoArtifact
    {
        $status = $this->getTaskStatus($taskId);

        if (! $status->status->isSuccess()) {
            throw new \LogicException(
                "Task {$taskId} not completed (status: {$status->status->value})"
            );
        }

        return new VideoArtifact(
            taskId:          $taskId,
            videoUrl:        $status->videoUrl ?? '',
            thumbnailUrl:    null,
            durationSeconds: 5.0,
        );
    }

    public function cancelTask(string $taskId): void
    {
        // FAL queue cancellation is best-effort; silently ignore errors.
        try {
            Http::withHeaders($this->headers())
                ->timeout($this->timeout)
                ->delete($this->endpointUrl() . "/requests/{$taskId}");
        } catch (\Throwable) {}
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function headers(): array
    {
        return [
            'Authorization' => "Key {$this->apiKey}",
            'Content-Type'  => 'application/json',
        ];
    }

    private function endpointUrl(): string
    {
        return self::QUEUE_BASE . '/' . $this->model . '/text-to-video';
    }

    private function extractVideoUrlFromBody(array $body): ?string
    {
        return $body['video']['url']         // {"video": {"url": "..."}}
            ?? $body['videos'][0]['url']     // {"videos": [{"url": "..."}]}
            ?? $body['output']['video']['url'] ?? null; // {"output": {"video": {"url": "..."}}}
    }

    private function fetchVideoUrl(string $taskId): ?string
    {
        $fetchUrl = $this->resultUrls[$taskId]
            ?? (self::QUEUE_BASE . "/requests/{$taskId}");

        $response = Http::withHeaders($this->headers())
            ->timeout($this->timeout)
            ->get($fetchUrl);

        if ($response->failed()) {
            return null;
        }

        return $this->extractVideoUrlFromBody($response->json() ?? []);
    }

    /**
     * @throws KlingApiException on non-2xx or FAL error body
     */
    private function assertOk(\Illuminate\Http\Client\Response $response, string $context): void
    {
        if ($response->failed()) {
            $body = $response->json() ?? [];
            throw new KlingApiException(
                new Dto\ApiError(
                    code:       $body['code'] ?? $response->status(),
                    message:    "FAL {$context} failed: " . ($body['detail'] ?? $response->body()),
                    requestId:  '',
                    httpStatus: $response->status(),
                )
            );
        }
    }
}
