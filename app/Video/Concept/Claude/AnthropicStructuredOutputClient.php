<?php

declare(strict_types=1);

namespace App\Video\Concept\Claude;

use App\Video\Concept\Contracts\StructuredOutputLlmClient;
use App\Video\Concept\Exceptions\AnthropicRefusalException;
use App\Video\Concept\Exceptions\AnthropicRequestException;
use App\Video\Concept\Exceptions\AnthropicTruncatedOutputException;
use App\Video\Llm\AnthropicStreamTimeout;
use App\Video\Llm\AnthropicTransport;
use App\Video\Llm\AnthropicTransportError;
use App\Video\Llm\ClaudeStreamClient;
use App\Video\Llm\ClaudeStreamRun;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use RuntimeException;

final class AnthropicStructuredOutputClient implements StructuredOutputLlmClient
{
    /** @var list<string> */
    public const EFFORTS = ['low', 'medium', 'high', 'xhigh', 'max'];

    /** @var list<string> stop reasons that end a complete turn */
    private const FINISHED = ['end_turn', 'stop_sequence'];

    private readonly AnthropicTransport $transport;

    /**
     * @param  ?array<string, mixed>  $thinking  sent as the request's `thinking` only when set
     * @param  ?int  $idleTimeoutSeconds  longest silence while streaming; null waits until the total timeout
     */
    public function __construct(
        HttpFactory $http,
        private readonly string $apiKey,
        string $baseUrl,
        string $apiVersion,
        private readonly int $timeoutSeconds,
        private readonly int $retryTimes,
        private readonly int $retrySleepMs,
        private readonly bool $stream = false,
        private readonly ?string $effort = null,
        private readonly ?array $thinking = null,
        private readonly ?int $idleTimeoutSeconds = null,
        private readonly int $progressEverySeconds = 5,
    ) {
        if ($this->effort !== null && ! in_array($this->effort, self::EFFORTS, true)) {
            throw new RuntimeException('Anthropic effort must be one of '.implode(', ', self::EFFORTS).'.');
        }

        if (trim($this->apiKey) === '') {
            throw new RuntimeException('Anthropic API key is not configured.');
        }

        if ($this->timeoutSeconds < 1) {
            throw new RuntimeException('Anthropic timeout must be >= 1 second.');
        }

        if ($this->retryTimes < 0) {
            throw new RuntimeException('Anthropic retryTimes must be >= 0.');
        }

        if ($this->retrySleepMs < 0) {
            throw new RuntimeException('Anthropic retrySleepMs must be >= 0.');
        }

        if ($this->idleTimeoutSeconds !== null && $this->idleTimeoutSeconds < 1) {
            throw new RuntimeException('Anthropic idle timeout must be >= 1 second.');
        }

        $this->transport = new AnthropicTransport($http, $apiKey, $baseUrl, $apiVersion);
    }

    /**
     * @param  list<array{role:string, content:string|list<array<string,mixed>>}>  $messages
     * @param  array<string,mixed>  $outputSchema  [] sends no grammar: the schema lives in the prompt and the caller checks the answer
     * @param  ?\Closure(array<string, mixed>): (bool|null)  $onProgress  this call's stream state; false stops the call
     */
    public function create(
        string $model,
        string $system,
        array $messages,
        array $outputSchema,
        int $maxTokens,
        ?\Closure $onProgress = null,
    ): AnthropicStructuredOutputResponse {
        if (trim($model) === '') {
            throw new AnthropicRequestException('Anthropic model must not be empty.');
        }

        if (trim($system) === '') {
            throw new AnthropicRequestException('Anthropic system prompt must not be empty.');
        }

        if ($messages === []) {
            throw new AnthropicRequestException('Anthropic messages must not be empty.');
        }

        if ($maxTokens < 1) {
            throw new AnthropicRequestException('Anthropic maxTokens must be >= 1.');
        }

        $this->extendPhpExecutionTime();
        $deadline = microtime(true) + $this->timeoutSeconds;
        $run = new ClaudeStreamRun($onProgress, $this->progressEverySeconds, $this->timeoutSeconds, $this->idleTimeoutSeconds, $this->thinking);

        if ($run->emit(true) === false) {
            $run->usageComplete = true;

            throw $this->fail($run, ClaudeStreamClient::ENDED_CANCELLED, 'The run lost its claim before the request was sent; nothing was sent.', null);
        }

        $beforeRetry = static fn (): ?string => $run->emit(true) === false ? AnthropicTransportError::CANCELLED : null;

        try {
            [$response, $run->attempts] = $this->transport->send($this->body($model, $system, $messages, $outputSchema, $maxTokens), $this->stream, $deadline, $this->idleTimeoutSeconds, $this->attempts(), $this->retrySleepMs, $beforeRetry);
        } catch (AnthropicTransportError $e) {
            $run->attempts = $e->attempts;
            $run->httpStatus = $e->status;
            $run->requestId = $e->requestId;
            $run->usageComplete = $e->kind !== AnthropicTransportError::NO_HEADERS;
            $sent = $e->status === null ? '' : " ({$e->attempts} sent, the last one answered HTTP {$e->status})";

            throw $this->fail($run, match ($e->kind) {
                AnthropicTransportError::HTTP => ClaudeStreamClient::ENDED_HTTP_ERROR,
                AnthropicTransportError::CANCELLED => ClaudeStreamClient::ENDED_CANCELLED,
                AnthropicTransportError::OUT_OF_TIME => ClaudeStreamClient::ENDED_TOTAL_TIMEOUT,
                default => ClaudeStreamClient::ENDED_CONNECT_ERROR,
            }, match ($e->kind) {
                AnthropicTransportError::NO_HEADERS => sprintf('Anthropic sent no response headers within %.0f s (%s); it is unknown whether the request was processed, so it may still be billed.', $e->waitedSeconds, $e->getMessage()),
                AnthropicTransportError::CANCELLED, AnthropicTransportError::OUT_OF_TIME => $e->getMessage().$sent,
                default => $e->getMessage(),
            }, null, $e);
        }

        $requestId = $response->header('request-id') ?: null;
        $run->requestId = $requestId;
        $run->httpStatus = $response->status();
        $json = $this->stream ? $this->readStream($response, $run, $deadline, $model, $requestId) : $response->json();

        if (! is_array($json)) {
            throw $this->fail($run, ClaudeStreamClient::ENDED_STREAM_CUT, 'Anthropic returned a non-object response.', null);
        }

        $stopReason = isset($json['stop_reason']) ? (string) $json['stop_reason'] : null;
        $usage = is_array($json['usage'] ?? null) ? $json['usage'] : [];
        $run->stopReason = $stopReason;
        $run->ended = ClaudeStreamClient::ENDED_MESSAGE_STOP;
        $failedResponse = in_array($stopReason, ['refusal', 'max_tokens'], true)
            ? $this->partial($json, $model, $requestId, true, $this->stream ? null : $response->body())
            : null;

        if ($stopReason === 'refusal') {
            $run->error = 'refusal';
            $run->emit(true);

            throw new AnthropicRefusalException('Claude refused to generate the canonical concept.', response: $failedResponse);
        }

        if ($stopReason === 'max_tokens') {
            $run->error = 'max_tokens';
            $run->emit(true);

            throw new AnthropicTruncatedOutputException(
                'Claude canonical output was truncated by max_tokens after '.(int) ($usage['output_tokens'] ?? 0).' output tokens. Increase CANONICAL_CONCEPT_MAX_TOKENS.',
                response: $failedResponse,
            );
        }

        if (! in_array($stopReason, self::FINISHED, true)) {
            throw $this->fail($run, ClaudeStreamClient::ENDED_MESSAGE_STOP, 'Claude stopped with stop_reason '.($stopReason ?? 'none').', so the answer is not a finished turn.', $this->partial($json, $model, $requestId, true, $this->stream ? null : $response->body()));
        }

        $text = $this->extractText($json, $run, $model, $requestId);
        $run->emit(true);

        return new AnthropicStructuredOutputResponse(
            rawText: $text,
            model: isset($json['model']) ? (string) $json['model'] : $model,
            stopReason: $stopReason,
            inputTokens: (int) ($usage['input_tokens'] ?? 0),
            outputTokens: (int) ($usage['output_tokens'] ?? 0),
            requestId: $requestId,
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

    /** Number of HTTP requests one call may send, retries included. */
    public function attempts(): int
    {
        return max($this->retryTimes, 1);
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  array<string, mixed>  $outputSchema
     * @return array<string, mixed>
     */
    private function body(string $model, string $system, array $messages, array $outputSchema, int $maxTokens): array
    {
        $outputConfig = $outputSchema === [] ? [] : ['format' => ['type' => 'json_schema', 'schema' => $outputSchema]];

        if ($this->effort !== null) {
            $outputConfig['effort'] = $this->effort;
        }

        $body = ['model' => $model, 'max_tokens' => $maxTokens, 'system' => $system, 'messages' => $messages];

        if ($outputConfig !== []) {
            $body['output_config'] = $outputConfig;
        }

        if ($this->thinking !== null) {
            $body['thinking'] = $this->thinking;
        }

        if ($this->stream) {
            $body['stream'] = true;
        }

        return $body;
    }

    /** @return array<string, mixed> */
    private function readStream(Response $response, ClaudeStreamRun $run, float $deadline, string $model, ?string $requestId): array
    {
        $message = ['content' => [], 'usage' => []];
        $stream = null;

        try {
            $stream = AnthropicTransport::events($response, $deadline, $this->idleTimeoutSeconds);

            foreach ($stream->events() as $payload) {
                if (($error = $run->apply($payload)) !== null) {
                    throw $this->fail($run, ClaudeStreamClient::ENDED_STREAM_ERROR, 'Anthropic stream error: '.(string) ($error['type'] ?? 'unknown').': '.(string) ($error['message'] ?? ''), $this->partial($message, $model, $requestId, $run->usageComplete));
                }

                self::accumulate($payload, $message);

                if ($run->ended === ClaudeStreamClient::ENDED_MESSAGE_STOP) {
                    break;
                }

                if ($run->emit() === false) {
                    throw $this->fail($run, ClaudeStreamClient::ENDED_CANCELLED, 'The run lost its claim, so the stream was stopped.', $this->partial($message, $model, $requestId, $run->usageComplete));
                }
            }
        } catch (AnthropicStreamTimeout $e) {
            throw $this->fail(
                $run,
                $e->kind === AnthropicStreamTimeout::IDLE ? ClaudeStreamClient::ENDED_IDLE_TIMEOUT : ClaudeStreamClient::ENDED_TOTAL_TIMEOUT,
                $e->kind === AnthropicStreamTimeout::IDLE
                    ? "Anthropic stream went silent for {$this->idleTimeoutSeconds} s after ".(int) ($message['usage']['output_tokens'] ?? 0).' output tokens.'
                    : "Anthropic stream ran past the {$this->timeoutSeconds} s limit after ".(int) ($message['usage']['output_tokens'] ?? 0).' output tokens.',
                $this->partial($message, $model, $requestId, $run->usageComplete),
                $e,
            );
        } catch (AnthropicRequestException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw $this->fail($run, ClaudeStreamClient::ENDED_STREAM_ERROR, 'Reading the Anthropic stream failed: '.$e->getMessage(), $this->partial($message, $model, $requestId, $run->usageComplete), $e);
        } finally {
            $stream?->close();
        }

        if ($run->ended !== ClaudeStreamClient::ENDED_MESSAGE_STOP) {
            throw $this->fail($run, ClaudeStreamClient::ENDED_STREAM_CUT, 'Anthropic stream ended before message_stop after '.(int) ($message['usage']['output_tokens'] ?? 0).' output tokens.', $this->partial($message, $model, $requestId, $run->usageComplete));
        }

        $message['content'] = array_values($message['content']);

        return $message;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $message
     */
    private static function accumulate(array $payload, array &$message): void
    {
        switch ($payload['type'] ?? null) {
            case 'message_start':
                $start = is_array($payload['message'] ?? null) ? $payload['message'] : [];
                $message['id'] = $start['id'] ?? null;
                $message['model'] = $start['model'] ?? null;
                $message['usage'] = is_array($start['usage'] ?? null) ? $start['usage'] : [];
                break;

            case 'content_block_start':
                $message['content'][(int) ($payload['index'] ?? 0)] = is_array($payload['content_block'] ?? null) ? $payload['content_block'] : [];
                break;

            case 'content_block_delta':
                $index = (int) ($payload['index'] ?? 0);
                $delta = is_array($payload['delta'] ?? null) ? $payload['delta'] : [];

                if (($delta['type'] ?? null) === 'text_delta') {
                    $message['content'][$index]['text'] = ($message['content'][$index]['text'] ?? '').(string) ($delta['text'] ?? '');
                } elseif (($delta['type'] ?? null) === 'thinking_delta') {
                    $message['content'][$index]['thinking'] = ($message['content'][$index]['thinking'] ?? '').(string) ($delta['thinking'] ?? '');
                }
                break;

            case 'message_delta':
                $delta = is_array($payload['delta'] ?? null) ? $payload['delta'] : [];

                if (array_key_exists('stop_reason', $delta)) {
                    $message['stop_reason'] = $delta['stop_reason'];
                }

                if (is_array($payload['usage'] ?? null)) {
                    $message['usage'] = array_replace($message['usage'], $payload['usage']);
                }
                break;
        }
    }

    /** @param array<string, mixed> $message */
    private function partial(array $message, string $model, ?string $requestId, bool $usageComplete, ?string $raw = null): AnthropicStructuredOutputResponse
    {
        $usage = is_array($message['usage'] ?? null) ? $message['usage'] : [];
        $message['content'] = array_values((array) ($message['content'] ?? []));

        return new AnthropicStructuredOutputResponse(
            rawText: $raw ?? (string) json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            model: (string) ($message['model'] ?? $model),
            stopReason: isset($message['stop_reason']) ? (string) $message['stop_reason'] : null,
            inputTokens: (int) ($usage['input_tokens'] ?? 0),
            outputTokens: (int) ($usage['output_tokens'] ?? 0),
            requestId: $requestId,
            usageComplete: $usageComplete,
        );
    }

    private function fail(ClaudeStreamRun $run, string $ended, string $message, ?AnthropicStructuredOutputResponse $partial, ?\Throwable $previous = null): AnthropicRequestException
    {
        $run->ended = $ended;
        $run->error = $message;
        $run->emit(true);

        return new AnthropicRequestException($message, previous: $previous, response: $partial);
    }

    private function extendPhpExecutionTime(): void
    {
        $attempts = $this->attempts();
        $seconds = $this->timeoutSeconds + (int) ceil($this->retrySleepMs * ($attempts - 1) / 1000) + 30;

        if (function_exists('ini_set')) {
            @ini_set('max_execution_time', (string) $seconds);
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit($seconds);
        }
    }

    /** @param array<string,mixed> $response */
    private function extractText(array $response, ClaudeStreamRun $run, string $model, ?string $requestId): string
    {
        $content = $response['content'] ?? null;

        if (! is_array($content) || ! array_is_list($content)) {
            throw $this->fail($run, ClaudeStreamClient::ENDED_MESSAGE_STOP, 'Anthropic response.content must be a list.', $this->partial($response, $model, $requestId, true));
        }

        $texts = [];

        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $texts[] = $block['text'];
            }
        }

        $text = trim(implode('', $texts));

        if ($text === '') {
            throw $this->fail($run, ClaudeStreamClient::ENDED_MESSAGE_STOP, 'Anthropic response did not contain structured text output.', $this->partial($response, $model, $requestId, true));
        }

        return $text;
    }
}
