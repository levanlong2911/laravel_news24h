<?php

namespace App\Video\Inspiration;

final class SourceInsight
{
    /** @param list<string> $sourceQuotes */
    public function __construct(
        public readonly string $aspect,
        public readonly string $summary,
        public readonly array $sourceQuotes,
    ) {}

    /** @return array{aspect: string, summary: string, source_quotes: list<string>} */
    public function toArray(): array
    {
        return [
            'aspect' => $this->aspect,
            'summary' => $this->summary,
            'source_quotes' => $this->sourceQuotes,
        ];
    }
}
