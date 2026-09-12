<?php

declare(strict_types=1);

namespace App\Video\Prompt;

use App\Video\Prompt\Exceptions\TextCompletionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use RuntimeException;
use Throwable;

final class AnthropicTextClient implements TextCompletionClient
{
    private const ENDPOINT = '/v1/messages';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly string $apiVersion,
        private readonly int $timeoutSeconds,
        private readonly int $retryTimes,
        private readonly int $retrySleepMs,
    ) {
        if (trim($this->apiKey) === '') {
            throw new RuntimeException('Anthropic API key is not configured.');
        }

        if ($this->timeoutSeconds < 1) {
            throw new RuntimeException('Anthropic timeout must be >= 1 second.');
        }

        if ($this->retryTimes < 0) {
            throw new RuntimeException('Anthropic retryTimes must be >= 0.');
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
        if ($outputSchema !== null) {
            throw new TextCompletionException(
                'AnthropicTextClient does not implement structured output. '
                .'Use AnthropicStructuredOutputClient for schema-constrained calls.'
            );
        }

        if (trim($system) === '' || trim($user) === '') {
            throw new TextCompletionException('System and user content must both be non-empty.');
        }

        $response = $this->http
            ->withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => $this->apiVersion,
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
            ->post(rtrim($this->baseUrl, '/').self::ENDPOINT, [
                'model' => $model,
                'max_tokens' => $maxTokens,
                'system' => $system,
                'messages' => [
                    ['role' => 'user', 'content' => $user],
                ],
            ]);

        if ($response->failed()) {
            $error = $response->json('error') ?? [];

            throw new TextCompletionException(sprintf(
                'Anthropic %d (%s): %s',
                $response->status(),
                (string) ($error['type'] ?? 'unknown'),
                (string) ($error['message'] ?? mb_substr($response->body(), 0, 300)),
            ));
        }

        $blocks = $response->json('content') ?? [];
        $text = '';

        foreach (is_array($blocks) ? $blocks : [] as $block) {
            if (($block['type'] ?? null) === 'text') {
                $text .= (string) ($block['text'] ?? '');
            }
        }

        if (trim($text) === '') {
            throw new TextCompletionException('Anthropic returned no text content.');
        }

        $requestId = $response->header('request-id');

        return new TextCompletionResponse(
            text: trim($text),
            model: (string) ($response->json('model') ?? $model),
            stopReason: (string) ($response->json('stop_reason') ?? ''),
            inputTokens: (int) ($response->json('usage.input_tokens') ?? 0),
            outputTokens: (int) ($response->json('usage.output_tokens') ?? 0),
            requestId: is_string($requestId) && trim($requestId) !== '' ? $requestId : null,
        );
    }
}
