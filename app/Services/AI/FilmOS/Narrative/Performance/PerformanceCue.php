<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Performance;

/**
 * Level 2 — one OBSERVABLE physical behavior: "eyes flick left",
 * "jaw tightens", "half breath".
 *
 * $channel is nullable by design: "hesitates" plays on no single channel
 * and must not be forced into one.
 *
 * Semantic intent, never vendor wording — the adapter turns a cue sequence
 * into prose ("He briefly holds his breath, his jaw tightens…").
 *
 * Immutable.
 */
final class PerformanceCue
{
    public function __construct(
        public readonly string              $description,
        public readonly ?PerformanceChannel $channel = null,
    ) {}
}
