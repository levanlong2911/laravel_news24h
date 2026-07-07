<?php

namespace App\Services\AI\Provider\Kling;

use App\Services\AI\Provider\Kling\Dto\SubmitVideoRequest;
use App\Services\AI\Provider\Kling\Dto\SubmitVideoResponse;
use App\Services\AI\Provider\Kling\Dto\TaskStatusResponse;
use App\Services\AI\Provider\Kling\Dto\VideoArtifact;
use Illuminate\Support\Facades\Http;

/**
 * HTTP client for the Kling AI video generation API.
 *
 * Responsibilities:
 *   - JWT generation and Bearer auth
 *   - HTTP execution and error detection
 *   - Delegates request building to KlingRequestFactory
 *   - Delegates response parsing to KlingResponseMapper
 *
 * Auth: Kling uses JWT Bearer — header.payload signed with HMAC-SHA256(accessKeySecret).
 * Payload: {iss: accessKeyId, exp: now+1800, nbf: now-5}
 *
 * @see https://docs.klingai.com (Kling API reference)
 */
final class KlingApiClient implements KlingApiClientInterface
{
    public function __construct(
        private readonly string               $accessKeyId,
        private readonly string               $accessKeySecret,
        private readonly string               $baseUrl,
        private readonly int                  $timeout,
        private readonly KlingRequestFactory  $requestFactory = new KlingRequestFactory(),
        private readonly KlingResponseMapper  $responseMapper = new KlingResponseMapper(),
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            accessKeyId:     (string) config('kling.access_key_id'),
            accessKeySecret: (string) config('kling.access_key_secret'),
            baseUrl:         (string) config('kling.base_url', 'https://api.klingai.com'),
            timeout:         (int)    config('kling.timeout', 30),
        );
    }

    // ── Public API ────────────────────────────────────────────────────────────

    public function submitVideoTask(SubmitVideoRequest $request): SubmitVideoResponse
    {
        $body = $this->post('/v1/videos/text2video', $this->requestFactory->buildSubmitPayload($request));
        return $this->responseMapper->toSubmitVideoResponse($body);
    }

    public function getTaskStatus(string $taskId): TaskStatusResponse
    {
        $body = $this->get("/v1/videos/text2video/{$taskId}");
        return $this->responseMapper->toTaskStatusResponse($body);
    }

    public function downloadResult(string $taskId): VideoArtifact
    {
        $status = $this->getTaskStatus($taskId);

        if (! $status->status->isSuccess()) {
            throw new \LogicException(
                "Task {$taskId} is not completed (status: {$status->status->value})."
            );
        }

        return $this->responseMapper->toVideoArtifact($status);
    }

    public function cancelTask(string $taskId): void
    {
        // Best-effort: Kling API may not support cancellation in all versions.
        try {
            Http::withToken($this->generateJwt())
                ->timeout($this->timeout)
                ->delete($this->baseUrl . "/v1/videos/text2video/{$taskId}");
        } catch (\Throwable) {
            // Cancellation is not guaranteed; caller should not depend on success.
        }
    }

    // ── HTTP helpers ──────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>      $payload
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload): array
    {
        $response = Http::withToken($this->generateJwt())
            ->timeout($this->timeout)
            ->post($this->baseUrl . $path, $payload);

        return $this->unwrap($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $path): array
    {
        $response = Http::withToken($this->generateJwt())
            ->timeout($this->timeout)
            ->get($this->baseUrl . $path);

        return $this->unwrap($response);
    }

    /**
     * @return array<string, mixed>
     * @throws KlingApiException on HTTP error or Kling error code ≠ 0
     */
    private function unwrap(\Illuminate\Http\Client\Response $response): array
    {
        $body = $response->json() ?? [];

        if ($response->failed() || (int) ($body['code'] ?? 0) !== 0) {
            throw new KlingApiException(
                $this->responseMapper->toApiError($body, $response->status())
            );
        }

        return $body;
    }

    // ── JWT ───────────────────────────────────────────────────────────────────

    private function generateJwt(): string
    {
        $header  = $this->base64UrlEncode((string) json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = $this->base64UrlEncode((string) json_encode([
            'iss' => $this->accessKeyId,
            'exp' => time() + 1800,
            'nbf' => time() - 5,
        ]));
        $sig = hash_hmac('sha256', "{$header}.{$payload}", $this->accessKeySecret, true);

        return "{$header}.{$payload}." . $this->base64UrlEncode($sig);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
