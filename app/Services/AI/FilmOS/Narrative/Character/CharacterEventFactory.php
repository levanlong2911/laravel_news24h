<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Character;

use App\Services\AI\FilmOS\Narrative\Timeline\Clock;
use App\Services\AI\FilmOS\Narrative\Timeline\Events\CharacterEmotionChangedEvent;
use App\Services\AI\FilmOS\Narrative\Timeline\Events\CharacterIntroducedEvent;
use App\Services\AI\FilmOS\Narrative\Timeline\SemanticEvent;
use App\Services\AI\FilmOS\Narrative\Timeline\TimelineOrdinal;
use Illuminate\Support\Str;

/**
 * Translates character intent into SemanticEvents for the Timeline.
 *
 * The only class permitted to create Character-domain events.
 * Does not know World, Scene, GoalNode, or Planning domain.
 */
final class CharacterEventFactory
{
    public function __construct(private readonly Clock $clock) {}

    /**
     * @param  CharacterProfile[]  $profiles  characters entering the story
     * @param  int                 $ordinal   ordinal of introduction (default: BASELINE)
     * @return SemanticEvent[]
     */
    public function introductions(
        array $profiles,
        int   $ordinal = TimelineOrdinal::BASELINE,
    ): array {
        $now = $this->clock->now();

        return array_map(
            fn(CharacterProfile $profile) => new CharacterIntroducedEvent(
                eventId:     (string) Str::ulid(),
                aggregateId: "character:{$profile->id}",
                shotOrdinal: $ordinal,
                occurredAt:  $now,
                profile:     $profile,
            ),
            array_values($profiles),
        );
    }

    public function emotionChange(
        string           $characterId,
        CharacterEmotion $emotion,
        int              $ordinal,
    ): SemanticEvent {
        return new CharacterEmotionChangedEvent(
            eventId:     (string) Str::ulid(),
            aggregateId: "character:{$characterId}",
            shotOrdinal: $ordinal,
            occurredAt:  $this->clock->now(),
            characterId: $characterId,
            emotion:     $emotion,
        );
    }
}
