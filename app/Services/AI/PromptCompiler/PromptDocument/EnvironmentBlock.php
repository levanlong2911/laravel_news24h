<?php

namespace App\Services\AI\PromptCompiler\PromptDocument;

final class EnvironmentBlock
{
    public function __construct(
        public readonly string  $envKey,      // "garage", "highway", "studio"
        public readonly string  $description, // full rich expansion
        public readonly bool    $isFallback,  // true = derived from light code, not semantic env field
    ) {}
}
