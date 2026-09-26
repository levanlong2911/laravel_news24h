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
    /** @var list<string> */
    public const EFFORTS = ['low', 'medium', 'high', 'xhigh', 'max'];

    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly string $apiVersion,
        private readonly int $timeoutSeconds,
        private readonly int $retryTimes,
        private readonly int $retrySleepMs,
        private readonly bool $stream = false,
        private readonly ?string $effort = null,
    ) {
        if ($this->effort !== null && ! in_array($this->effort, self::EFFORTS, true)) {
            throw new RuntimeException(
                'Anthropic effort must be one of '.implode(', ', self::EFFORTS).'.'
            );
        }

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

        $outputConfig = [
            'format' => [
                'type' => 'json_schema',

                'schema' => $outputSchema,
            ],
        ];

        if ($this->effort !== null) {
            $outputConfig['effort'] = $this->effort;
        }

        $body = [
            'model' => $model,

            'max_tokens' => $maxTokens,

            'system' => $system,

            'messages' => $messages,

            'output_config' => $outputConfig,
        ];

        if ($this->stream) {
            $body['stream'] = true;
        }

        $request =
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
                );

        if ($this->stream) {
            $request = $request->withOptions(['stream' => true]);
        }

        $response = $request->post(
            rtrim(
                $this->baseUrl,
                '/'
            )
            .'/v1/messages',
            $body
        );

        if (! $response->successful()) {
            throw new AnthropicRequestException(
                $this->httpErrorMessage(
                    $response
                )
            );
        }

        $json = $this->stream
            ? $this->readStream($response)
            : $response->json();

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
                rawText: $this->stream
                    ? (string) json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : $response->body(),
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

    public function streams(): bool
    {
        return $this->stream;
    }

    public function effort(): ?string
    {
        return $this->effort;
    }

    /**
     * @return array<string, mixed>
     */
    private function readStream(Response $response): array
    {
        $stream = $response->toPsrResponse()->getBody();
        $message = ['content' => [], 'usage' => []];
        $buffer = '';
        $finished = false;

        while (! $stream->eof()) {
            $buffer .= str_replace("\r\n", "\n", $stream->read(8192));

            while (($end = strpos($buffer, "\n\n")) !== false) {
                $event = substr($buffer, 0, $end);
                $buffer = substr($buffer, $end + 2);
                $finished = $this->applyStreamEvent($event, $message) || $finished;
            }
        }

        if (trim($buffer) !== '') {
            $finished = $this->applyStreamEvent($buffer, $message) || $finished;
        }

        if (! $finished) {
            throw new AnthropicRequestException(
                'Anthropic stream ended before message_stop after '
                .(int) ($message['usage']['output_tokens'] ?? 0)
                .' output tokens.'
            );
        }

        $message['content'] = array_values($message['content']);

        return $message;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function applyStreamEvent(string $event, array &$message): bool
    {
        $data = '';

        foreach (explode("\n", $event) as $line) {
            if (str_starts_with($line, 'data:')) {
                $data .= ltrim(substr($line, 5));
            }
        }

        if ($data === '') {
            return false;
        }

        $payload = json_decode($data, true);

        if (! is_array($payload)) {
            return false;
        }

        switch ($payload['type'] ?? null) {
            case 'message_start':
                $start = is_array($payload['message'] ?? null) ? $payload['message'] : [];
                $message['id'] = $start['id'] ?? null;
                $message['model'] = $start['model'] ?? null;
                $message['usage'] = is_array($start['usage'] ?? null) ? $start['usage'] : [];

                return false;

            case 'content_block_start':
                $block = is_array($payload['content_block'] ?? null) ? $payload['content_block'] : [];
                $message['content'][(int) ($payload['index'] ?? 0)] = $block;

                return false;

            case 'content_block_delta':
                $index = (int) ($payload['index'] ?? 0);
                $delta = is_array($payload['delta'] ?? null) ? $payload['delta'] : [];

                if (($delta['type'] ?? null) === 'text_delta') {
                    $message['content'][$index]['text'] = ($message['content'][$index]['text'] ?? '')
                        .(string) ($delta['text'] ?? '');
                } elseif (($delta['type'] ?? null) === 'thinking_delta') {
                    $message['content'][$index]['thinking'] = ($message['content'][$index]['thinking'] ?? '')
                        .(string) ($delta['thinking'] ?? '');
                }

                return false;

            case 'message_delta':
                $delta = is_array($payload['delta'] ?? null) ? $payload['delta'] : [];

                if (array_key_exists('stop_reason', $delta)) {
                    $message['stop_reason'] = $delta['stop_reason'];
                }

                if (is_array($payload['usage'] ?? null)) {
                    $message['usage'] = array_replace($message['usage'], $payload['usage']);
                }

                return false;

            case 'message_stop':
                return true;

            case 'error':
                throw new AnthropicRequestException(
                    'Anthropic stream error: '
                    .(string) ($payload['error']['type'] ?? 'unknown')
                    .': '
                    .(string) ($payload['error']['message'] ?? '')
                );

            default:
                return false;
        }
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
