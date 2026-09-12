<?php

declare(strict_types=1);

namespace App\Video\Prompt;

use App\Video\Prompt\Exceptions\TextCompletionException;
use App\Video\Prompt\Exceptions\TextCompletionRefusalException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use RuntimeException;
use Throwable;

final class OpenAiTextClient implements TextCompletionClient
{
    private const ENDPOINT = '/v1/chat/completions';

    private const SCHEMA_NAME = 'structured_output';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly string $reasoningEffort,
        private readonly int $timeoutSeconds,
        private readonly int $retryTimes,
        private readonly int $retrySleepMs,
    ) {
        if (trim($this->apiKey) === '') {
            throw new RuntimeException('OpenAI API key is not configured.');
        }

        if ($this->timeoutSeconds < 1) {
            throw new RuntimeException('OpenAI timeout must be >= 1 second.');
        }

        if ($this->retryTimes < 0) {
            throw new RuntimeException('OpenAI retryTimes must be >= 0.');
        }

        if ($this->retrySleepMs < 0) {
            throw new RuntimeException('OpenAI retrySleepMs must be >= 0.');
        }
    }

    /**
     * @param  array<string,mixed>|null  $outputSchema
     */
    public function complete(
        string $model,
        string $system,
        string $user,
        int $maxTokens,
        ?array $outputSchema = null,
    ): TextCompletionResponse {
        if (trim($system) === '' || trim($user) === '') {
            throw new TextCompletionException('System and user content must both be non-empty.');
        }

        if ($maxTokens < 1) {
            throw new TextCompletionException('OpenAI maxTokens must be >= 1.');
        }

        $this->extendPhpExecutionTime();

        $response = $this->http
            ->withHeaders([
                'Authorization' => 'Bearer '.$this->apiKey,
                'content-type' => 'application/json',
            ])
            ->timeout($this->timeoutSeconds)
            ->retry(
                $this->retryTimes,
                $this->retrySleepMs,
                when: static fn (Throwable $exception): bool =>
                    $exception instanceof RequestException
                    && (
                        $exception->response->status() === 429
                        || $exception->response->serverError()
                    ),
                throw: false,
            )
            ->post(
                rtrim($this->baseUrl, '/').self::ENDPOINT,
                $this->payload($model, $system, $user, $maxTokens, $outputSchema),
            );

        if ($response->failed()) {
            $error = $response->json('error') ?? [];

            throw new TextCompletionException(sprintf(
                'OpenAI %d (%s): %s',
                $response->status(),
                (string) ($error['code'] ?? $error['type'] ?? 'unknown'),
                (string) ($error['message'] ?? mb_substr($response->body(), 0, 300)),
            ));
        }

        $refusal = $response->json('choices.0.message.refusal');

        if (is_string($refusal) && trim($refusal) !== '') {
            throw new TextCompletionRefusalException($refusal);
        }

        $text = (string) ($response->json('choices.0.message.content') ?? '');

        if (trim($text) === '') {
            throw new TextCompletionException('OpenAI returned no text content.');
        }

        $requestId = $response->header('x-request-id');

        return new TextCompletionResponse(
            text: trim($text),
            model: (string) ($response->json('model') ?? $model),
            stopReason: $this->stopReason((string) ($response->json('choices.0.finish_reason') ?? '')),
            inputTokens: (int) ($response->json('usage.prompt_tokens') ?? 0),
            outputTokens: (int) ($response->json('usage.completion_tokens') ?? 0),
            requestId: is_string($requestId) && trim($requestId) !== '' ? $requestId : null,
            reasoningTokens: (int) ($response->json('usage.completion_tokens_details.reasoning_tokens') ?? 0),
        );
    }

    /**
     * @param  array<string,mixed>|null  $outputSchema
     * @return array<string,mixed>
     */
    private function payload(
        string $model,
        string $system,
        string $user,
        int $maxTokens,
        ?array $outputSchema,
    ): array {
        $payload = [
            'model' => $model,
            'max_completion_tokens' => $maxTokens,
            'reasoning_effort' => $this->reasoningEffort,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
        ];

        if ($outputSchema !== null) {
            $payload['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => self::SCHEMA_NAME,
                    'strict' => true,
                    'schema' => $outputSchema,
                ],
            ];
        }

        return $payload;
    }

    private function stopReason(string $finishReason): string
    {
        return $finishReason === 'length' ? 'max_tokens' : $finishReason;
    }

    private function extendPhpExecutionTime(): void
    {
        $seconds = ($this->timeoutSeconds * ($this->retryTimes + 1))
            + (int) ceil($this->retrySleepMs * $this->retryTimes / 1000)
            + 30;

        if (function_exists('ini_set')) {
            @ini_set('max_execution_time', (string) $seconds);
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit($seconds);
        }
    }
}
