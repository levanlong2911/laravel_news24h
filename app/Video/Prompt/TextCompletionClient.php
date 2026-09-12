<?php

declare(strict_types=1);

namespace App\Video\Prompt;

interface TextCompletionClient
{
    /**
     * @param  array<string,mixed>|null  $outputSchema
     */
    public function complete(
        string $model,
        string $system,
        string $user,
        int $maxTokens,
        ?array $outputSchema = null,
    ): TextCompletionResponse;
}
