<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Timeline\Bridge;

use App\Services\AI\FilmOS\Narrative\Timeline\Events\ShotPlannedEvent;
use App\Services\AI\FilmOS\Narrative\Timeline\Projection\ProjectionHandler;
use App\Services\AI\FilmOS\Narrative\Timeline\Projection\ProjectionPriority;
use App\Services\AI\FilmOS\Narrative\Timeline\ProjectionContext;
use App\Services\AI\FilmOS\Narrative\Timeline\SemanticEvent;

final class ShotPlannedProjectionHandler implements ProjectionHandler
{
    public function priority(): int { return ProjectionPriority::STORY; }

    public function supports(SemanticEvent $event): bool
    {
        return $event instanceof ShotPlannedEvent;
    }

    public function apply(SemanticEvent $event, ProjectionContext $context): void
    {
        assert($event instanceof ShotPlannedEvent);

        $context->builder->addShot(
            shotId:      $event->shotId,
            ordinal:     $event->shotOrdinal(),
            goalType:    $event->goalType,
            description: $event->description,
        );
    }
}
