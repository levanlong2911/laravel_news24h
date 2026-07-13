<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Timeline\Projection;

use App\Services\AI\FilmOS\Narrative\Timeline\ProjectionContext;
use App\Services\AI\FilmOS\Narrative\Timeline\SemanticEvent;

interface ProjectionHandler
{
    /** Execution order: lower number runs first.
     *  Convention: World=100, Character=200, Scene=300.
     *  Default (no ordering dependency): return 0. */
    public function priority(): int;

    /** Return true if this handler processes $event.
     *  DefaultTimelineProjector calls ALL handlers where supports() returns true. */
    public function supports(SemanticEvent $event): bool;

    public function apply(SemanticEvent $event, ProjectionContext $context): void;
}
