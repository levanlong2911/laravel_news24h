<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Scene;

use App\Services\AI\FilmOS\Narrative\Timeline\Events\SceneNodePlacedEvent;
use App\Services\AI\FilmOS\Narrative\Timeline\Projection\ProjectionHandler;
use App\Services\AI\FilmOS\Narrative\Timeline\Projection\ProjectionPriority;
use App\Services\AI\FilmOS\Narrative\Timeline\ProjectionContext;
use App\Services\AI\FilmOS\Narrative\Timeline\SemanticEvent;

final class SceneNodePlacedHandler implements ProjectionHandler
{
    public function priority(): int { return ProjectionPriority::SCENE; }

    public function supports(SemanticEvent $event): bool
    {
        return $event instanceof SceneNodePlacedEvent;
    }

    public function apply(SemanticEvent $event, ProjectionContext $context): void
    {
        assert($event instanceof SceneNodePlacedEvent);
        $context->builder->upsertSceneNode($event->node);
    }
}
