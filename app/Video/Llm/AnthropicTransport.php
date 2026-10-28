<?php

declare(strict_types=1);

namespace App\Video\Llm;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;

final class AnthropicTransport
{
    /** @var list<int> statuses Anthropic answers before it generates anything, so sending again cannot pay twice */
    public const RETRYABLE_STATUSES = [429, 500, 502, 503, 504, 529];

    private const CONNECT_SECONDS = 10.0;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly string $apiVersion,
    ) {}

    /**
     * @param  array<string, mixed>  $body
     * @param  float  $deadline  microtime(true) by which the call ends, retries and the pauses between them included
     * @param  ?int  $idleSeconds  longest wait for the next bytes; null waits until the deadline
     * @param  ?\Closure(int): ?string  $beforeRetry  asked before every request after the first, once the pause is over: null sends it, AnthropicTransportError::CANCELLED or OUT_OF_TIME stops
     * @return array{0: Response, 1: int} the successful response and how many requests were sent
     *
     * @throws AnthropicTransportError
     */
    public function send(array $body, bool $stream, float $deadline, ?int $idleSeconds, int $attempts, int $retrySleepMs, ?\Closure $beforeRetry = null): array
    {
        $attempts = max(1, $attempts);
        $started = microtime(true);
        $last = null;

        for ($attempt = 1; ; $attempt++) {
            $stop = $attempt > 1 && $beforeRetry !== null ? $beforeRetry($attempt) : null;

            if ($stop !== null) {
                throw new AnthropicTransportError(
                    $stop === AnthropicTransportError::CANCELLED ? 'The run was stopped before a request was sent again.' : 'The time budget ran out before a request could be sent again.',
                    $stop,
                    $attempt - 1,
                    microtime(true) - $started,
                    $last?->status,
                    $last?->errorType,
                    $last?->providerMessage,
                    $last?->requestId,
                );
            }

            $wait = self::wait($deadline, $idleSeconds);

            if ($wait <= 0.0) {
                throw new AnthropicTransportError('The time budget ran out before a request could be sent.', AnthropicTransportError::OUT_OF_TIME, $attempt - 1, microtime(true) - $started, $last?->status, $last?->errorType, $last?->providerMessage, $last?->requestId);
            }

            $sentAt = microtime(true);

            try {
                $request = $this->http
                    ->withHeaders([
                        'x-api-key' => $this->apiKey,
                        'anthropic-version' => $this->apiVersion,
                        'content-type' => 'application/json',
                    ] + ($stream ? ['accept' => 'text/event-stream'] : []))
                    ->withOptions([
                        'timeout' => $wait,
                        'read_timeout' => $wait,
                        'connect_timeout' => min(self::CONNECT_SECONDS, $wait),
                    ] + ($stream ? ['stream' => true] : []));
                $response = $request->post(rtrim($this->baseUrl, '/').'/v1/messages', $body);
            } catch (\Throwable $e) {
                throw new AnthropicTransportError($e->getMessage(), AnthropicTransportError::NO_HEADERS, $attempt, microtime(true) - $sentAt, previous: $e);
            }

            if ($response->successful()) {
                return [$response, $attempt];
            }

            $raw = $response->body();
            $json = json_decode($raw, true);
            $error = is_array($json) && is_array($json['error'] ?? null) ? $json['error'] : [];
            $pause = $retrySleepMs / 1000;
            $last = new AnthropicTransportError(
                sprintf('Anthropic API failed [%d]: %s', $response->status(), (string) ($error['message'] ?? mb_substr($raw, 0, 500))),
                AnthropicTransportError::HTTP,
                $attempt,
                microtime(true) - $started,
                $response->status(),
                isset($error['type']) ? (string) $error['type'] : null,
                isset($error['message']) ? (string) $error['message'] : null,
                $response->header('request-id') ?: null,
            );

            if ($attempt < $attempts && in_array($response->status(), self::RETRYABLE_STATUSES, true) && microtime(true) + $pause < $deadline) {
                usleep($retrySleepMs * 1000);

                continue;
            }

            throw $last;
        }
    }

    public static function events(Response $response, float $deadline, ?int $idleSeconds): AnthropicEventStream
    {
        return new AnthropicEventStream($response->toPsrResponse()->getBody(), $deadline, $idleSeconds);
    }

    public static function wait(float $deadline, ?int $idleSeconds): float
    {
        $remaining = $deadline - microtime(true);

        return $idleSeconds === null ? $remaining : min((float) $idleSeconds, $remaining);
    }
}
