<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Benchmark\Selection;

/** One beat's predicted focus beside the author's, unjudged. */
final class FocusComparison
{
    public function __construct(
        public readonly string $beat,
        public readonly string $predicted,
        public readonly ?string $reference,
    ) {}

    public function matches(): bool
    {
        return $this->reference !== null && $this->reference === $this->predicted;
    }
}
