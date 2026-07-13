<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Timeline;

/** Not final — D5 may extend: class QAProjectionContext extends ProjectionContext
 *  to add $previousState, $logger, $repairDepth, etc. */
class ProjectionContext
{
    public function __construct(
        public readonly NarrativeStateBuilder $builder,
        public readonly ?int                  $upToOrdinal,
        public readonly ?string               $productionId = null,
    ) {}
}
