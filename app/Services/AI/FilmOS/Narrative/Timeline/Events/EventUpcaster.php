<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Timeline\Events;

use App\Services\AI\FilmOS\Narrative\Timeline\SemanticEvent;

/** Not used in D0. Defines the vocabulary for event schema migration.
 *
 * When a concrete event gains a new required field (v1 → v2):
 *   1. Implement a concrete upcaster for that event class.
 *   2. Register in FilmOSServiceProvider.
 *   3. DefaultTimelineProjector runs upcasters before calling handlers.
 *
 * This keeps old stored events readable by new code without modifying stored data. */
interface EventUpcaster
{
    public function canUpcast(string $eventClass, int $fromVersion): bool;

    public function upcast(SemanticEvent $event): SemanticEvent;
}
