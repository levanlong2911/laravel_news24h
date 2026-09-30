<?php

declare(strict_types=1);

namespace App\Video\Screenplay;

use App\Services\Admin\ClaudeWriterService;
use App\Video\Concept\Claude\AnthropicStructuredOutputClient;
use App\Video\Concept\Contracts\StructuredOutputLlmClient;
use App\Video\Prompt\Exceptions\TextCompletionException;
use Illuminate\Support\Facades\Log;

final class ScreenplayAuthor
{
    public const DEFAULT_CONTRACT = 'screenplay_v2';

    /** @var list<string> */
    private const DOCUMENTS = [
        '00_writer_contract.md',
    ];

    private const EXAMPLE_INPUT = '04_worked_example.input.json';

    private const EXAMPLE_OUTPUT = '04_worked_example.json';

    private const EXAMPLE_NOTES = '04_worked_example.md';

    /** @var list<string> */
    private const EXAMPLE_GUIDANCE = [
        'A WORKED EXAMPLE',
        'Its subject is deliberately unlike yours. Copy the way the decisions',
        'were made. Never copy its story, its characters, its locations or its',
        'sentences.',
        'It shows how scene boundaries are drawn and how action is written.',
        'It does not stand in for the design work this assignment asks of you.',
    ];

    private ?array $validatedSchema = null;

    public function __construct(
        private readonly StructuredOutputLlmClient $client,
        private readonly string $promptDir,
        private readonly string $schemaPath,
        private readonly string $promptVersion,
        private readonly string $model,
        private readonly int $maxTokens,
        private readonly string $contractVersion = self::DEFAULT_CONTRACT,
        /** @var list<string> */
        private readonly array $exampleGuidance = self::EXAMPLE_GUIDANCE,
        private readonly string $sourceKey = 'inspiration',
    ) {
        if (! in_array($contractVersion, ScreenplayValidator::ALL_CONTRACTS, true)) {
            throw new TextCompletionException("Unsupported screenplay contract: {$contractVersion}");
        }
    }

    public function contractVersion(): string
    {
        return $this->contractVersion;
    }

    private function logEnded(float $startedAt, string $reason, ?string $rawText): void
    {
        Log::warning('screenplay: request ended without a usable response', [
            'contract' => $this->contractVersion,
            'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'response_bytes' => $rawText === null ? 0 : strlen($rawText),
            'reason' => $reason,
        ]);
    }

    public function assertSchemaMatchesContract(): void
    {
        $this->schema();
    }

    /** @return array<string, mixed> */
    public function contractSchema(): array
    {
        return $this->schema();
    }

    /**
     * @param  array<string, mixed>  $inspiration
     * @param  array<string, mixed>  $profile
     * @param  array<string, mixed>  $requirements
     * @param  array<string, mixed>  $fixed
     * @param  array<string, mixed>|null  $constrainedSchema
     *
     * @throws ScreenplayFailure
     */
    public function author(
        array $inspiration,
        array $profile,
        array $requirements,
        array $fixed = [],
        ?array $constrainedSchema = null,
    ): ScreenplayResult {
        $system = $this->rules();
        $userMessage = $this->input($inspiration, $profile, $requirements, $fixed);
        $outputSchema = $this->schema();

        if ($constrainedSchema !== null) {
            $outputSchema = $constrainedSchema;
        }
        $startedAt = microtime(true);

        Log::info('screenplay: request started', [
            'contract' => $this->contractVersion,
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'timeout_seconds' => $this->client instanceof AnthropicStructuredOutputClient
                ? $this->client->timeoutSeconds()
                : null,
            'attempts' => $this->client instanceof AnthropicStructuredOutputClient
                ? $this->client->attempts()
                : null,
            'stream' => $this->client instanceof AnthropicStructuredOutputClient
                ? $this->client->streams()
                : null,
            'effort' => $this->client instanceof AnthropicStructuredOutputClient
                ? $this->client->effort()
                : null,
            'system_bytes' => strlen($system),
            'input_bytes' => strlen($userMessage),
            'schema_bytes' => strlen((string) json_encode($outputSchema)),
        ]);

        try {
            $response = $this->client->create(
                model: $this->model,
                system: $system,
                messages: [[
                    'role' => 'user',
                    'content' => $userMessage,
                ]],
                outputSchema: $outputSchema,
                maxTokens: $this->maxTokens,
            );
        } catch (\App\Video\Concept\Exceptions\AnthropicTruncatedOutputException|\App\Video\Concept\Exceptions\AnthropicRefusalException $e) {
            $this->logEnded($startedAt, $e->getMessage(), $e->response?->rawText);

            if ($e->response === null) {
                throw $e;
            }

            $truncated = $e instanceof \App\Video\Concept\Exceptions\AnthropicTruncatedOutputException;

            throw new ScreenplayFailure(
                $truncated
                    ? 'Screenplay was cut off at the token limit; raise max_tokens.'
                    : 'Claude refused to generate the screenplay.',
                $e->response->rawText,
                $this->usageOf($e->response),
                $truncated ? ScreenplayFailure::TRUNCATED : ScreenplayFailure::REFUSED,
            );
        } catch (\Throwable $e) {
            $this->logEnded($startedAt, $e->getMessage(), null);

            throw $e;
        }

        Log::info('screenplay: response received', [
            'contract' => $this->contractVersion,
            'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'response_bytes' => strlen($response->rawText),
            'stop_reason' => $response->stopReason,
            'tokens_in' => $response->inputTokens,
            'tokens_out' => $response->outputTokens,
        ]);

        $usage = $this->usageOf($response);

        if ($response->stopReason === 'max_tokens') {
            throw new ScreenplayFailure(
                'Screenplay was cut off at the token limit; raise max_tokens.',
                $response->rawText,
                $usage,
                ScreenplayFailure::TRUNCATED,
            );
        }

        try {
            $decoded = json_decode($response->rawText, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ScreenplayFailure(
                'Screenplay response is not valid JSON: '.$exception->getMessage(),
                $response->rawText,
                $usage,
            );
        }

        if (! is_array($decoded)) {
            throw new ScreenplayFailure(
                'Screenplay response is not a JSON object.',
                $response->rawText,
                $usage,
            );
        }

        return new ScreenplayResult(
            screenplay: $decoded,
            rawResponse: $response->rawText,
            authorModel: $response->model,
            usage: $usage,
        );
    }

    /**
     * @param  array<string, mixed>  $inspiration
     * @param  array<string, mixed>  $profile
     * @param  array<string, mixed>  $requirements
     * @param  array<string, mixed>  $fixed
     */
    public function fingerprint(array $inspiration, array $profile, array $requirements, array $fixed = []): string
    {
        return hash('sha256', json_encode([
            'inspiration' => $inspiration,
            'profile' => $profile,
            'requirements' => $requirements,
            'model' => $this->model,
            'prompt_version' => $this->promptVersion,
            'schema_version' => $this->contractVersion,
            'rules' => hash('sha256', $this->rules()),
            'schema' => hash('sha256', json_encode($this->schema())),
        ] + ($fixed === [] ? [] : ['fixed' => $fixed]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function rules(): string
    {
        $parts = [];

        foreach (self::DOCUMENTS as $name) {
            $parts[] = $this->read($name);
        }

        $parts[] = implode("\n\n", [
            ...$this->exampleGuidance,
            'GIVEN THIS INPUT:',
            $this->read(self::EXAMPLE_INPUT),
            'A VALID ANSWER IS:',
            $this->read(self::EXAMPLE_OUTPUT),
            $this->read(self::EXAMPLE_NOTES),
        ]);

        return implode("\n\n", $parts);
    }

    /**
     * @param  array<string, mixed>  $inspiration
     * @param  array<string, mixed>  $profile
     * @param  array<string, mixed>  $requirements
     * @param  array<string, mixed>  $fixed
     */
    private function input(array $inspiration, array $profile, array $requirements, array $fixed = []): string
    {
        return json_encode(
            [$this->sourceKey => $inspiration] + $fixed + [
                'profile' => $profile,
                'film_requirements' => $requirements,
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
        );
    }

    /** @return array<string, mixed> */
    private function schema(): array
    {
        if ($this->validatedSchema !== null) {
            return $this->validatedSchema;
        }

        $configured = $this->readSchema($this->schemaPath);
        $expected = $this->readSchema(resource_path("ai/screenplay/schemas/{$this->contractVersion}.json"));

        if (json_encode($this->normalizeSchema($configured), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)
            !== json_encode($this->normalizeSchema($expected), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)) {
            throw new TextCompletionException("Configured screenplay schema does not match {$this->contractVersion}.");
        }

        // Use the matching canonical document to preserve existing fingerprints and key order.
        return $this->validatedSchema = json_decode(
            json_encode($expected, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    private function readSchema(string $path): \stdClass
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new TextCompletionException('Screenplay schema is not readable: '.$path);
        }

        try {
            $schema = json_decode((string) file_get_contents($path), false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new TextCompletionException('Screenplay schema is not valid JSON: '.$path, 0, $e);
        }

        if (! $schema instanceof \stdClass || ($schema->type ?? null) !== 'object') {
            throw new TextCompletionException('Screenplay schema must describe an object: '.$path);
        }

        return $schema;
    }

    private function normalizeSchema(mixed $value): mixed
    {
        // Decode objects separately so {} and [] (including numeric keys) stay distinct.
        if ($value instanceof \stdClass) {
            $properties = get_object_vars($value);
            ksort($properties, SORT_STRING);
            $normalized = new \stdClass;
            foreach ($properties as $key => $child) {
                $normalized->{$key} = $this->normalizeSchema($child);
            }

            return $normalized;
        }

        if (is_array($value)) {
            return array_map(fn (mixed $child): mixed => $this->normalizeSchema($child), $value);
        }

        return $value;
    }

    private function read(string $name): string
    {
        $path = rtrim($this->promptDir, '/\\').DIRECTORY_SEPARATOR.$name;

        if (! is_file($path)) {
            throw new TextCompletionException('Screenplay document not found: '.$path);
        }

        return (string) file_get_contents($path);
    }

    /** @return array<string, mixed> */
    private function usageOf(object $response): array
    {
        return [
            'model' => 'sonnet5',
            'provider_model' => $response->model,
            'instruction_version' => $this->promptVersion,
            'tokens_in' => $response->inputTokens,
            'tokens_out' => $response->outputTokens,
            'cost_usd' => ClaudeWriterService::costUsd(
                $response->inputTokens,
                $response->outputTokens,
                'sonnet5',
            ),
        ];
    }
}
