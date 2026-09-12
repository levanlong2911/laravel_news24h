<?php

declare(strict_types=1);

namespace App\Video\Concept\OpenAi;

use App\Video\Concept\Claude\AnthropicStructuredOutputResponse;
use App\Video\Concept\Contracts\StructuredOutputLlmClient;
use App\Video\Concept\Exceptions\AnthropicRefusalException;
use App\Video\Concept\Exceptions\AnthropicRequestException;
use App\Video\Concept\Exceptions\AnthropicTruncatedOutputException;
use App\Video\Prompt\Exceptions\TextCompletionRefusalException;
use App\Video\Prompt\TextCompletionClient;

final class TextClientStructuredAdapter implements StructuredOutputLlmClient
{
    public function __construct(
        private readonly TextCompletionClient $client,
    ) {}

    /**
     * @param  list<array{role:string,content:string}>  $messages
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
            throw new AnthropicRequestException('Structured output model must not be empty.');
        }

        if (trim($system) === '') {
            throw new AnthropicRequestException('Structured output system prompt must not be empty.');
        }

        if ($messages === []) {
            throw new AnthropicRequestException('Structured output messages must not be empty.');
        }

        if ($outputSchema === []) {
            throw new AnthropicRequestException('Structured output schema must not be empty.');
        }

        if ($maxTokens < 1) {
            throw new AnthropicRequestException('Structured output maxTokens must be >= 1.');
        }

        try {
            $response = $this->client->complete(
                model: $model,
                system: $system,
                user: $this->flatten($messages),
                maxTokens: $maxTokens,
                outputSchema: $outputSchema,
            );
        } catch (TextCompletionRefusalException $e) {
            throw new AnthropicRefusalException(
                'Model refused to generate the canonical concept: '.$e->getMessage(),
                previous: $e,
            );
        }

        if ($response->wasTruncated()) {
            throw new AnthropicTruncatedOutputException(sprintf(
                'Canonical output was truncated by the token budget after %d completion '
                .'tokens (%d of them reasoning). Raise the provider max_tokens or lower '
                .'the reasoning effort.',
                $response->outputTokens,
                $response->reasoningTokens,
            ));
        }

        return new AnthropicStructuredOutputResponse(
            rawText: $response->text,
            model: $response->model,
            stopReason: $response->stopReason,
            inputTokens: $response->inputTokens,
            outputTokens: $response->outputTokens,
            requestId: $response->requestId,
        );
    }

    /**
     * @param  list<array{role:string,content:string}>  $messages
     */
    private function flatten(array $messages): string
    {
        $parts = [];

        foreach ($messages as $message) {
            $role = (string) ($message['role'] ?? '');

            if ($role !== 'user') {
                throw new AnthropicRequestException(
                    "Structured output adapter supports only user messages, got '{$role}'."
                );
            }

            $content = (string) ($message['content'] ?? '');

            if (trim($content) === '') {
                throw new AnthropicRequestException('Structured output message content must not be empty.');
            }

            $parts[] = $content;
        }

        return implode("\n\n", $parts);
    }
}
