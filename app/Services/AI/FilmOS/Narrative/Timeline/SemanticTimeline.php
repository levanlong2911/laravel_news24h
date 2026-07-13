<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Timeline;

interface SemanticTimeline
{
    public function append(SemanticEvent $event): void;

    /** All events in append order. Returns iterable — implementations may yield
     *  (generator) for large timelines without loading all into memory. */
    public function events(): iterable;

    /** Events with shotOrdinal <= $upToOrdinal (null = all).
     *  D0–D4: pass null.  D5 QA+Repair: pass N to project state before shot N. */
    public function replay(?int $upToOrdinal = null): iterable;
}
