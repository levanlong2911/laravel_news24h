<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Runtime;

use App\Services\AI\FilmOS\FilmOSError;
use App\Services\AI\FilmOS\Render\ProviderSerializer;
use App\Services\AI\FilmOS\Render\RenderIR;

final class RenderRuntime
{
    public function __construct(
        private readonly ProviderSerializer $serializer,
        private readonly ProviderClient     $client,
        private readonly RetryPolicy        $retryPolicy,
    ) {}

    public function run(RenderIR $ir): ProviderResult
    {
        if (!$this->serializer->supports($ir)) {
            throw new FilmOSError(
                errorCode:   'PROVIDER_NOT_SUPPORTED',
                layer:       'Runtime',
                reason:      "Provider '{$this->serializer->provider()}' does not support shot '{$ir->shotId}' (duration {$ir->durationSeconds}s)",
                recoverable: false,
                metadata:    ['shotId' => $ir->shotId, 'duration' => $ir->durationSeconds],
            );
        }

        $result = $this->client->submit($ir->traceId, $this->serializer->serialize($ir));
        $result = $this->waitForCompletion($result);

        if ($result->status === RuntimeEvent::COMPLETED) {
            $result = $this->client->download($result);
        }

        return $result;
    }

    private function waitForCompletion(ProviderResult $result): ProviderResult
    {
        $pollCount = 0;

        while ($result->status === RuntimeEvent::SUBMITTED || $result->status === RuntimeEvent::POLLING) {
            if ($pollCount >= $this->retryPolicy->maxAttempts) {
                return new ProviderResult(
                    traceId:   $result->traceId,
                    provider:  $result->provider,
                    requestId: $result->requestId,
                    status:    RuntimeEvent::TIMEOUT,
                    metadata:  ['pollCount' => $pollCount],
                );
            }

            $this->doSleep($this->retryPolicy->backoff);
            $result = $this->client->poll($result);
            $pollCount++;
        }

        return $result;
    }

    private function doSleep(float $seconds): void
    {
        if ($seconds > 0) {
            usleep((int) ($seconds * 1_000_000));
        }
    }
}
