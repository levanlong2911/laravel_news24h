<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Scene;

use App\Services\AI\FilmOS\Narrative\Timeline\Events\SceneNodeRemovedEvent;
use App\Services\AI\FilmOS\Narrative\Timeline\Projection\ProjectionHandler;
use App\Services\AI\FilmOS\Narrative\Timeline\Projection\ProjectionPriority;
use App\Services\AI\FilmOS\Narrative\Timeline\ProjectionContext;
use App\Services\AI\FilmOS\Narrative\Timeline\SemanticEvent;

final class SceneNodeRemovedHandler implements ProjectionHandler
{
    public function priority(): int { return ProjectionPriority::SCENE; }

    public function supports(SemanticEvent $event): bool
    {
        return $event instanceof SceneNodeRemovedEvent;
    }

    public function apply(SemanticEvent $event, ProjectionContext $context): void
    {
        assert($event instanceof SceneNodeRemovedEvent);
        $context->builder->removeSceneNode($event->nodeId);
    }
}
