<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Character;

use App\Services\AI\FilmOS\Narrative\Timeline\Events\CharacterEmotionChangedEvent;
use App\Services\AI\FilmOS\Narrative\Timeline\Projection\ProjectionHandler;
use App\Services\AI\FilmOS\Narrative\Timeline\Projection\ProjectionPriority;
use App\Services\AI\FilmOS\Narrative\Timeline\ProjectionContext;
use App\Services\AI\FilmOS\Narrative\Timeline\SemanticEvent;

final class CharacterEmotionChangedHandler implements ProjectionHandler
{
    public function priority(): int { return ProjectionPriority::CHARACTER; }

    public function supports(SemanticEvent $event): bool
    {
        return $event instanceof CharacterEmotionChangedEvent;
    }

    public function apply(SemanticEvent $event, ProjectionContext $context): void
    {
        assert($event instanceof CharacterEmotionChangedEvent);
        $context->builder->recordEmotion($event->characterId, $event->shotOrdinal(), $event->emotion);
    }
}
