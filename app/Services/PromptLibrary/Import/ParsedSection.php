<?php

namespace App\Services\PromptLibrary\Import;

final class ParsedSection
{
    public function __construct(
        public readonly string $key,
        public readonly ?string $parentKey,
        public readonly ?string $heading,
        public readonly int $level,
        public readonly string $kind,
        public readonly int $startLine,
        public readonly int $endLine,
        public readonly string $body,
    ) {}
}
