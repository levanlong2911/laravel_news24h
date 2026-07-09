<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Provider;

/**
 * Successful response from a provider render call.
 *
 * prompt is stored so test assertions can verify the exact payload
 * that was sent to the provider — useful for detecting prompt drift
 * between planning runs.
 */
final class ProviderResponse
{
    public function __construct(
        public readonly string  $taskId,
        public readonly string  $videoUrl,
        public readonly string  $prompt         = '',
        public readonly float   $latencyMs      = 0.0,
        public readonly ?string $providerTaskId = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'taskId'         => $this->taskId,
            'videoUrl'       => $this->videoUrl,
            'prompt'         => $this->prompt,
            'latencyMs'      => $this->latencyMs,
            'providerTaskId' => $this->providerTaskId,
        ];
    }
}
