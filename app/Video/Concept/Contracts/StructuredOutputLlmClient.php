<?php

declare(strict_types=1);

namespace App\Video\Concept\Contracts;

use App\Video\Concept\Claude\AnthropicStructuredOutputResponse;

interface StructuredOutputLlmClient
{
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
    ): AnthropicStructuredOutputResponse;
}
