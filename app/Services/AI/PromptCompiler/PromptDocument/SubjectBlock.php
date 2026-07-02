<?php

namespace App\Services\AI\PromptCompiler\PromptDocument;

final class SubjectBlock
{
    public function __construct(
        public readonly string $actorDisplay,      // "experienced motorcycle mechanic"
        public readonly string $actionAdverb,      // "carefully"
        public readonly string $actionGerund,      // "installing"
        public readonly string $objectDisplay,     // "premium leather motorcycle seat"
        public readonly string $enrichedSentence,  // full composed sentence for prompt
    ) {}
}
