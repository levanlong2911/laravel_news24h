<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Character;

/**
 * Read-only view of character memory state at a projection point.
 *
 * D5 (QA) and PromptCompiler depend on this interface, not CharacterProjection.
 */
interface CharacterView
{
    public function hasCharacter(string $characterId): bool;

    /** The full memory timeline of one character, or null if never introduced. */
    public function memoryOf(string $characterId): ?CharacterMemory;

    /**
     * Last known emotion of the character at or before $ordinal
     * (persistence semantics — delegates to CharacterMemory::emotionAt()).
     */
    public function emotionAt(string $characterId, int $ordinal): ?CharacterEmotion;

    /** @return array<string, CharacterMemory> keyed by characterId */
    public function allCharacters(): array;
}
