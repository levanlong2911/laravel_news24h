<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompting\IR;

/**
 * Prompt IR for a whole production — one ShotPrompt per compilable shot.
 *
 * Keyed by ordinal (shot identity, D1 invariant): gaps are legal — shots
 * excluded by the compiler's blocking gate simply have no entry.
 *
 * Immutable.
 */
final class StructuredPrompt
{
    /** @param array<int, ShotPrompt> $shots keyed by shot ordinal */
    public function __construct(
        private readonly array $shots = [],
    ) {}

    /** @return array<int, ShotPrompt> keyed by shot ordinal */
    public function shots(): array
    {
        return $this->shots;
    }

    public function shotAt(int $ordinal): ?ShotPrompt
    {
        return $this->shots[$ordinal] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->shots === [];
    }
}
