<?php

declare(strict_types=1);

namespace App\Video\Prompt;

use App\Video\Prompt\Exceptions\TextCompletionException;
use App\Video\Prompt\Exceptions\TextCompletionRefusalException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
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
        private readonly bool $stream = false,
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

        return $this->send($model, $system, $user, $maxTokens, $outputSchema);
    }

    /**
     * @param  string  $imageDataUri  data:<mime>;base64,<bytes>
     * @param  array<string,mixed>|null  $outputSchema
     */
    public function completeWithImage(
        string $model,
        string $system,
        string $user,
        string $imageDataUri,
        int $maxTokens,
        ?array $outputSchema = null,
    ): TextCompletionResponse {
        if (trim($system) === '' || trim($user) === '') {
            throw new TextCompletionException('System and user content must both be non-empty.');
        }

        if (! str_starts_with($imageDataUri, 'data:image/') || ! str_contains($imageDataUri, ';base64,')) {
            throw new TextCompletionException('The image must be a base64 data URI.');
        }

        return $this->send($model, $system, [
            ['type' => 'text', 'text' => $user],
            ['type' => 'image_url', 'image_url' => ['url' => $imageDataUri]],
        ], $maxTokens, $outputSchema);
    }

    /**
     * @param  string|list<array<string,mixed>>  $user
     * @param  array<string,mixed>|null  $outputSchema
     */
    private function send(
        string $model,
        string $system,
        string|array $user,
        int $maxTokens,
        ?array $outputSchema,
    ): TextCompletionResponse {
        if ($maxTokens < 1) {
            throw new TextCompletionException('OpenAI maxTokens must be >= 1.');
        }

        $this->extendPhpExecutionTime();

        $request = $this->http
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
            );

        if ($this->stream) {
            $request = $request->withOptions(['stream' => true]);
        }

        $response = $request->post(
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

        $message = $this->stream ? $this->readStream($response) : [
            'content' => (string) ($response->json('choices.0.message.content') ?? ''),
            'refusal' => $response->json('choices.0.message.refusal'),
            'finish_reason' => (string) ($response->json('choices.0.finish_reason') ?? ''),
            'model' => $response->json('model'),
            'usage' => (array) ($response->json('usage') ?? []),
        ];

        if (is_string($message['refusal']) && trim($message['refusal']) !== '') {
            throw TextCompletionRefusalException::afterResponse(
                $message['refusal'], $message['usage'], $message['refusal'], $message['model'],
            );
        }

        if (trim($message['content']) === '') {
            throw TextCompletionException::afterResponse(
                'OpenAI returned no text content.', $message['usage'], $message['content'], $message['model'],
            );
        }

        $requestId = $response->header('x-request-id');

        return new TextCompletionResponse(
            text: trim($message['content']),
            model: (string) ($message['model'] ?? $model),
            stopReason: $this->stopReason((string) $message['finish_reason']),
            inputTokens: (int) ($message['usage']['prompt_tokens'] ?? 0),
            outputTokens: (int) ($message['usage']['completion_tokens'] ?? 0),
            requestId: is_string($requestId) && trim($requestId) !== '' ? $requestId : null,
            reasoningTokens: (int) ($message['usage']['completion_tokens_details']['reasoning_tokens'] ?? 0),
            usage: $message['usage'],
        );
    }

    public function streams(): bool
    {
        return $this->stream;
    }

    /**
     * @return array{content: string, refusal: ?string, finish_reason: string, model: ?string, usage: array<string, mixed>}
     */
    private function readStream(Response $response): array
    {
        $body = $response->toPsrResponse()->getBody();
        $message = ['content' => '', 'refusal' => null, 'finish_reason' => '', 'model' => null, 'usage' => []];
        $buffer = '';
        $done = false;

        while (! $body->eof()) {
            $buffer .= str_replace("\r\n", "\n", $body->read(8192));

            while (($end = strpos($buffer, "\n\n")) !== false) {
                $done = $this->applyStreamEvent(substr($buffer, 0, $end), $message) || $done;
                $buffer = substr($buffer, $end + 2);
            }
        }

        if (trim($buffer) !== '') {
            $done = $this->applyStreamEvent($buffer, $message) || $done;
        }

        if (! $done) {
            throw new TextCompletionException(
                'OpenAI stream ended before [DONE] after '.mb_strlen($message['content']).' characters.'
            );
        }

        return $message;
    }

    /**
     * @param  array{content: string, refusal: ?string, finish_reason: string, model: ?string, usage: array<string, mixed>}  $message
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

        if ($data === '[DONE]') {
            return true;
        }

        $chunk = json_decode($data, true);

        if (! is_array($chunk)) {
            return false;
        }

        if (is_array($chunk['error'] ?? null)) {
            throw new TextCompletionException(sprintf(
                'OpenAI stream error (%s): %s',
                (string) ($chunk['error']['code'] ?? $chunk['error']['type'] ?? 'unknown'),
                (string) ($chunk['error']['message'] ?? ''),
            ));
        }

        if (is_string($chunk['model'] ?? null)) {
            $message['model'] = $chunk['model'];
        }

        if (is_array($chunk['usage'] ?? null)) {
            $message['usage'] = $chunk['usage'];
        }

        $choice = is_array($chunk['choices'][0] ?? null) ? $chunk['choices'][0] : [];
        $delta = is_array($choice['delta'] ?? null) ? $choice['delta'] : [];

        if (is_string($delta['content'] ?? null)) {
            $message['content'] .= $delta['content'];
        }

        if (is_string($delta['refusal'] ?? null)) {
            $message['refusal'] = ($message['refusal'] ?? '').$delta['refusal'];
        }

        if (is_string($choice['finish_reason'] ?? null)) {
            $message['finish_reason'] = $choice['finish_reason'];
        }

        return false;
    }

    /**
     * @param  string|list<array<string,mixed>>  $user
     * @param  array<string,mixed>|null  $outputSchema
     * @return array<string,mixed>
     */
    private function payload(
        string $model,
        string $system,
        string|array $user,
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

        if ($this->stream) {
            $payload['stream'] = true;
            $payload['stream_options'] = ['include_usage' => true];
        }

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
