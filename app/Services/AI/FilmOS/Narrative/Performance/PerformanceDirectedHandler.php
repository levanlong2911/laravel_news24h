<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Performance;

use App\Services\AI\FilmOS\Narrative\Timeline\Events\PerformanceDirectedEvent;
use App\Services\AI\FilmOS\Narrative\Timeline\Projection\ProjectionHandler;
use App\Services\AI\FilmOS\Narrative\Timeline\Projection\ProjectionPriority;
use App\Services\AI\FilmOS\Narrative\Timeline\ProjectionContext;
use App\Services\AI\FilmOS\Narrative\Timeline\SemanticEvent;

final class PerformanceDirectedHandler implements ProjectionHandler
{
    public function priority(): int { return ProjectionPriority::PERFORMANCE; }

    public function supports(SemanticEvent $event): bool
    {
        return $event instanceof PerformanceDirectedEvent;
    }

    public function apply(SemanticEvent $event, ProjectionContext $context): void
    {
        assert($event instanceof PerformanceDirectedEvent);
        $context->builder->setPerformanceDesign($event->design);
    }
}
