<?php

namespace App\Services\AI\PromptCompiler\PromptDocument;

final class EmotionBlock
{
    public function __construct(
        public readonly string $code,       // "CRAFT"
        public readonly array  $modifiers,  // ["Quiet craftsmanship.", "Intimate precision.", ...]
        public readonly string $actionAdverb, // "carefully"
    ) {}
}
