<?php

namespace App\Services\AI\PromptCompiler\PromptDocument;

final class NegativeBlock
{
    public function __construct(
        /** @var string[] */
        public readonly array $phrases, // ["blurry", "low quality", "watermark", ...]
    ) {}
}
