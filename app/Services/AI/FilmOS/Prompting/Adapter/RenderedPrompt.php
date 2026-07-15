<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompting\Adapter;

/**
 * The output of a PromptRenderer — one vendor render request, structured so
 * the interface never changes as providers vary:
 *   - positive: the main prompt
 *   - negative: NEVER-constraints as this vendor's negative prompt (null if none)
 *   - metadata: per-vendor knobs (seed, cfg, motion_strength, duration…)
 *
 * Immutable.
 */
final class RenderedPrompt
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public readonly string  $positive,
        public readonly ?string $negative = null,
        public readonly array   $metadata = [],
    ) {}
}
