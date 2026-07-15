<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Production;

/**
 * What the audience must believe/feel — the director's objective for the piece.
 *
 * Objective ONLY: conflicts live in ConflictPlan (one objective may have many
 * conflicts — they are different kinds of knowledge).
 *
 * CONTRACT NOTE (flagged 2026-07-13): free-text $objective is the TEMPORARY v1
 * representation — it is still prose. Future evolution may introduce a typed
 * intent vocabulary (e.g. desired-emotion progression: FALSE_HOPE → RELEASE)
 * without changing this VO's narrative role. Do NOT treat free-text as the
 * permanent contract (same rule as EndingFrame).
 *
 * PRODUCTION BOUNDARY (frozen 2026-07-13): Production knowledge is semantic
 * and renderer-agnostic — reusable by Kling adapters today, Unreal/Blender/
 * Unity timelines tomorrow. NO prompt syntax, NO vendor wording, ever.
 *
 * Immutable.
 */
final class DirectorIntent
{
    public function __construct(
        public readonly string $objective,
    ) {}
}
