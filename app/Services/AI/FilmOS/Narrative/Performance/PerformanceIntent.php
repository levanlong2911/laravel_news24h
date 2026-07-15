<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Performance;

/**
 * Level 1 — the SEMANTIC inner direction: what the character is trying to
 * show (or hide). "suppress fear", "false confidence", "trying not to cry".
 *
 * Deliberately DISTINCT from D2 emotion: emotion is the state, intent is the
 * acting choice about that state — and may contradict it on purpose
 * (FEAR → nervous smile). That contradiction is art, not an error.
 *
 * CONTRACT NOTE (frozen 2026-07-13):
 * Free-text is intentionally temporary. Typed vocabulary only appears after
 * benchmark convergence — creating an enum now would be guessed taxonomy
 * (same rule as DirectorIntent/EndingFrame). The free-text collected across
 * benchmark rounds IS the data that designs the eventual vocabulary.
 *
 * Immutable.
 */
final class PerformanceIntent
{
    public function __construct(
        public readonly string  $intent,
        public readonly ?string $motivation = null,   // why: "does not want teammates to see doubt"
    ) {}
}
