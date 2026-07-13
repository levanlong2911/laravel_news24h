<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Runtime\Clients;

use App\Services\AI\FilmOS\Runtime\ProviderClient;
use App\Services\AI\FilmOS\Runtime\ProviderResult;
use App\Services\AI\FilmOS\Runtime\RuntimeEvent;
use App\Services\AI\Provider\Kling\Dto\SubmitVideoRequest;
use App\Services\AI\Provider\Kling\KlingApiClientInterface;
use App\Services\AI\Provider\Kling\KlingVideoStatus;

final class KlingClient implements ProviderClient
{
    public function __construct(private readonly KlingApiClientInterface $api) {}

    public function submit(string $traceId, array $payload): ProviderResult
    {
        $response = $this->api->submitVideoTask(new SubmitVideoRequest(
            prompt:          (string) $payload['prompt'],
            negativePrompt:  (string) ($payload['negative_prompt'] ?? ''),
            model:           (string) $payload['model_name'],
            mode:            (string) $payload['mode'],
            durationSeconds: (int) $payload['duration'],
            aspectRatio:     (string) $payload['aspect_ratio'],
            cfgScale:        (float) $payload['cfg_scale'],
        ));

        return new ProviderResult(
            traceId:   $traceId,
            provider:  'kling',
            requestId: $response->taskId,
            status:    RuntimeEvent::SUBMITTED,
        );
    }

    public function poll(ProviderResult $result): ProviderResult
    {
        $status = $this->api->getTaskStatus($result->requestId);

        return new ProviderResult(
            traceId:   $result->traceId,
            provider:  $result->provider,
            requestId: $result->requestId,
            status:    $this->mapStatus($status->status),
            assetUrl:  $status->videoUrl ?? '',
            duration:  $status->durationSeconds ?? 0.0,
            metadata:  $status->errorMessage ? ['error' => $status->errorMessage] : [],
        );
    }

    public function download(ProviderResult $result): ProviderResult
    {
        $artifact = $this->api->downloadResult($result->requestId);

        return new ProviderResult(
            traceId:   $result->traceId,
            provider:  $result->provider,
            requestId: $result->requestId,
            status:    RuntimeEvent::DOWNLOAD_COMPLETED,
            assetUrl:  $artifact->videoUrl,
            duration:  $artifact->durationSeconds,
            metadata:  array_filter(['thumbnailUrl' => $artifact->thumbnailUrl]),
        );
    }

    private function mapStatus(KlingVideoStatus $status): RuntimeEvent
    {
        return match ($status) {
            KlingVideoStatus::PENDING    => RuntimeEvent::SUBMITTED,
            KlingVideoStatus::PROCESSING => RuntimeEvent::POLLING,
            KlingVideoStatus::COMPLETED  => RuntimeEvent::COMPLETED,
            KlingVideoStatus::FAILED     => RuntimeEvent::FAILED,
        };
    }
}
