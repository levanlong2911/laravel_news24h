<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Timeline\Projection;

use App\Services\AI\FilmOS\Narrative\Character\CharacterEmotion;
use App\Services\AI\FilmOS\Narrative\Character\CharacterMemory;
use App\Services\AI\FilmOS\Narrative\Character\CharacterView;

/**
 * Snapshot of all character memories at a given timeline point.
 *
 * A Character Memory Projection, NOT a character registry:
 * each entry is a CharacterMemory (profile + emotional arc), and queries
 * like emotionAt() carry persistence semantics — state lives across shots
 * until an event changes it.
 *
 * D5 (QA) and PromptCompiler MUST depend on CharacterView, not this class.
 */
final class CharacterProjection implements CharacterView
{
    /**
     * @param array<string, CharacterMemory> $memories keyed by characterId
     */
    public function __construct(
        public readonly array $memories = [],
    ) {}

    public function hasCharacter(string $characterId): bool
    {
        return isset($this->memories[$characterId]);
    }

    public function memoryOf(string $characterId): ?CharacterMemory
    {
        return $this->memories[$characterId] ?? null;
    }

    public function emotionAt(string $characterId, int $ordinal): ?CharacterEmotion
    {
        return $this->memoryOf($characterId)?->emotionAt($ordinal);
    }

    /** @return array<string, CharacterMemory> */
    public function allCharacters(): array
    {
        return $this->memories;
    }
}
