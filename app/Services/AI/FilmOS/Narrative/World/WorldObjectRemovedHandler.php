<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\World;

use App\Services\AI\FilmOS\Narrative\Timeline\Events\WorldObjectRemovedEvent;
use App\Services\AI\FilmOS\Narrative\Timeline\Projection\ProjectionHandler;
use App\Services\AI\FilmOS\Narrative\Timeline\Projection\ProjectionPriority;
use App\Services\AI\FilmOS\Narrative\Timeline\ProjectionContext;
use App\Services\AI\FilmOS\Narrative\Timeline\SemanticEvent;

final class WorldObjectRemovedHandler implements ProjectionHandler
{
    public function priority(): int { return ProjectionPriority::WORLD; }

    public function supports(SemanticEvent $event): bool
    {
        return $event instanceof WorldObjectRemovedEvent;
    }

    public function apply(SemanticEvent $event, ProjectionContext $context): void
    {
        assert($event instanceof WorldObjectRemovedEvent);
        $context->builder->removeWorldObject($event->objectId);
    }
}
