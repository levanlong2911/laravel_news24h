<?php

namespace App\Services\AI\PromptCompiler\PromptDocument;

final class QualityBlock
{
    public function __construct(
        public readonly string $tier,    // "photoreal", "high", "medium", "low"
        public readonly array  $phrases, // ["Highly detailed.", "Sharp focus.", ...]
    ) {}
}
