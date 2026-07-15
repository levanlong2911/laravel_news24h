<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Timeline\Projection;

/**
 * Source of truth for handler priority ranges across the projection pipeline.
 *
 * Each layer owns a 100-wide range so intra-layer ordering can use relative offsets
 * without colliding with adjacent layers (e.g. WORLD + 1 = 101 is safe for a
 * secondary world handler that must run after the primary).
 *
 * Reserved layers not yet implemented are listed here so future authors
 * know which range to claim — no grep required.
 */
final class ProjectionPriority
{
    public const STORY       = 0;    // 0–99    D0 — narrative story events
    public const WORLD       = 100;  // 100–199 D3 — world state events
    public const CHARACTER   = 200;  // 200–299 D2 — character memory events
    public const SCENE       = 300;  // 300–399 D4 — scene graph events
    public const PRODUCTION  = 400;  // 400–499 Production — staging plan events
    public const PERFORMANCE = 500;  // 500–599 Performance — acting direction events
    public const QA          = 600;  // 600+    D5 — quality assurance events (reserved, no handlers yet)

    private function __construct() {}
}
