<?php

declare(strict_types=1);

namespace App\Video\Concept\Contracts;

use App\Video\Concept\Claude\AnthropicStructuredOutputResponse;

interface StructuredOutputLlmClient
{
    /**
     * @param  list<array{
     *     role:string,
     *     content:string|list<array<string,mixed>>
     * }>  $messages
     * @param  array<string,mixed>  $outputSchema
     * @param  ?\Closure(array<string, mixed>): (bool|null)  $onProgress  this call's stream state; false stops the call
     */
    public function create(
        string $model,
        string $system,
        array $messages,
        array $outputSchema,
        int $maxTokens,
        ?\Closure $onProgress = null,
    ): AnthropicStructuredOutputResponse;
}
