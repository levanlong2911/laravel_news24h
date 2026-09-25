<?php

declare(strict_types=1);

namespace App\Video\Concept\Claude;

use App\Video\Concept\Contracts\StructuredOutputLlmClient;
use App\Video\Concept\Exceptions\AnthropicRefusalException;
use App\Video\Concept\Exceptions\AnthropicRequestException;
use App\Video\Concept\Exceptions\AnthropicTruncatedOutputException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use RuntimeException;

final class AnthropicStructuredOutputClient implements StructuredOutputLlmClient
{
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
            throw new RuntimeException(
                'Anthropic API key is not configured.'
            );
        }

        if ($this->timeoutSeconds < 1) {
            throw new RuntimeException(
                'Anthropic timeout must be >= 1 second.'
            );
        }

        if ($this->retryTimes < 0) {
            throw new RuntimeException(
                'Anthropic retryTimes must be >= 0.'
            );
        }

        if ($this->retrySleepMs < 0) {
            throw new RuntimeException(
                'Anthropic retrySleepMs must be >= 0.'
            );
        }
    }

    /**
     * @param  list<array{
     *     role:string,
     *     content:string
     * }>  $messages
     * @param  array<string,mixed>  $outputSchema
     */
    public function create(
        string $model,
        string $system,
        array $messages,
        array $outputSchema,
        int $maxTokens,
    ): AnthropicStructuredOutputResponse {
        if (trim($model) === '') {
            throw new AnthropicRequestException(
                'Anthropic model must not be empty.'
            );
        }

        if (trim($system) === '') {
            throw new AnthropicRequestException(
                'Anthropic system prompt '
                .'must not be empty.'
            );
        }

        if ($messages === []) {
            throw new AnthropicRequestException(
                'Anthropic messages '
                .'must not be empty.'
            );
        }

        if ($maxTokens < 1) {
            throw new AnthropicRequestException(
                'Anthropic maxTokens must be >= 1.'
            );
        }

        $this->extendPhpExecutionTime();

        $response =
            $this->http
                ->withHeaders([
                    'x-api-key' => $this->apiKey,

                    'anthropic-version' => $this->apiVersion,

                    'content-type' => 'application/json',
                ])
                ->timeout(
                    $this->timeoutSeconds
                )
                ->retry(
                    $this->retryTimes,
                    $this->retrySleepMs,
                    throw: false
                )
                ->post(
                    rtrim(
                        $this->baseUrl,
                        '/'
                    )
                    .'/v1/messages',

                    [
                        'model' => $model,

                        'max_tokens' => $maxTokens,

                        'system' => $system,

                        'messages' => $messages,

                        'output_config' => [
                            'format' => [
                                'type' => 'json_schema',

                                'schema' => $outputSchema,
                            ],
                        ],
                    ]
                );

        if (! $response->successful()) {
            throw new AnthropicRequestException(
                $this->httpErrorMessage(
                    $response
                )
            );
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new AnthropicRequestException(
                'Anthropic returned '
                .'a non-object response.'
            );
        }

        $stopReason =
            isset($json['stop_reason'])
                ? (string) $json['stop_reason']
                : null;

        $usage =
            is_array(
                $json['usage'] ?? null
            )
                ? $json['usage']
                : [];

        // Keep the complete response even when there is no text block to extract.
        $failedResponse = in_array($stopReason, ['refusal', 'max_tokens'], true)
            ? new AnthropicStructuredOutputResponse(
                rawText: $response->body(),
                model: (string) ($json['model'] ?? $model),
                stopReason: $stopReason,
                inputTokens: (int) ($usage['input_tokens'] ?? 0),
                outputTokens: (int) ($usage['output_tokens'] ?? 0),
                requestId: $response->header('request-id'),
            )
            : null;

        /*
         * Structured output khong nen duoc
         * downstream parse neu model tu choi.
         */
        if ($stopReason === 'refusal') {
            throw new AnthropicRefusalException(
                'Claude refused to generate '
                .'the canonical concept.',
                response: $failedResponse,
            );
        }

        /*
         * Neu cham max_tokens,
         * JSON co the incomplete.
         *
         * Khong dua vao repair nhu semantic error;
         * day la provider execution failure.
         */
        if ($stopReason === 'max_tokens') {
            throw new AnthropicTruncatedOutputException(
                'Claude canonical output was '
                .'truncated by max_tokens '
                .'after '
                .(int) (
                    $usage['output_tokens']
                    ?? 0
                )
                .' output tokens. '
                .'Increase '
                .'CANONICAL_CONCEPT_MAX_TOKENS.',
                response: $failedResponse,
            );
        }

        $text =
            $this->extractText(
                $json
            );

        $requestId =
            $response->header(
                'request-id'
            );

        return new AnthropicStructuredOutputResponse(
            rawText: $text,

            model: isset($json['model'])
                    ? (string) $json['model']
                    : $model,

            stopReason: $stopReason,

            inputTokens: (int) (
                $usage['input_tokens']
                ?? 0
            ),

            outputTokens: (int) (
                $usage['output_tokens']
                ?? 0
            ),

            requestId: is_string($requestId)
                && trim($requestId) !== ''
                    ? $requestId
                    : null,
                );
    }

    public function timeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    /** Number of HTTP requests one call may send, retries included. */
    public function attempts(): int
    {
        return max($this->retryTimes, 1);
    }

    private function extendPhpExecutionTime(): void
    {
        $attempts = max($this->retryTimes, 1);

        $seconds =
            ($this->timeoutSeconds * $attempts)
            + (int) ceil(
                $this->retrySleepMs
                * ($attempts - 1)
                / 1000
            )
            + 30;

        if (function_exists('ini_set')) {
            @ini_set(
                'max_execution_time',
                (string) $seconds
            );
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit($seconds);
        }
    }

    /**
     * @param  array<string,mixed>  $response
     */
    private function extractText(
        array $response
    ): string {
        $content =
            $response['content']
            ?? null;

        if (
            ! is_array($content)
            || ! array_is_list($content)
        ) {
            throw new AnthropicRequestException(
                'Anthropic response.content '
                .'must be a list.'
            );
        }

        $texts = [];

        foreach ($content as $block) {
            if (! is_array($block)) {
                continue;
            }

            if (
                ($block['type'] ?? null)
                !== 'text'
            ) {
                continue;
            }

            if (
                ! isset($block['text'])
                || ! is_string(
                    $block['text']
                )
            ) {
                continue;
            }

            $texts[] =
                $block['text'];
        }

        $text = trim(
            implode('', $texts)
        );

        if ($text === '') {
            throw new AnthropicRequestException(
                'Anthropic response did not '
                .'contain structured text output.'
            );
        }

        return $text;
    }

    private function httpErrorMessage(
        Response $response
    ): string {
        $body =
            $response->json();

        $providerMessage = null;

        if (
            is_array($body)
            && is_array(
                $body['error'] ?? null
            )
            && isset(
                $body['error']['message']
            )
        ) {
            $providerMessage =
                (string) $body['error']['message'];
        }

        return sprintf(
            'Anthropic API failed [%d]: %s',
            $response->status(),
            $providerMessage
                ?: $response->body()
        );
    }
}
