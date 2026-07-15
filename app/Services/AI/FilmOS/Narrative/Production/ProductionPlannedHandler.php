<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Production;

use App\Services\AI\FilmOS\Narrative\Timeline\Events\ProductionPlannedEvent;
use App\Services\AI\FilmOS\Narrative\Timeline\Projection\ProjectionHandler;
use App\Services\AI\FilmOS\Narrative\Timeline\Projection\ProjectionPriority;
use App\Services\AI\FilmOS\Narrative\Timeline\ProjectionContext;
use App\Services\AI\FilmOS\Narrative\Timeline\SemanticEvent;

final class ProductionPlannedHandler implements ProjectionHandler
{
    public function priority(): int { return ProjectionPriority::PRODUCTION; }

    public function supports(SemanticEvent $event): bool
    {
        return $event instanceof ProductionPlannedEvent;
    }

    public function apply(SemanticEvent $event, ProjectionContext $context): void
    {
        assert($event instanceof ProductionPlannedEvent);
        $context->builder->setProductionPlan($event->plan);
    }
}
