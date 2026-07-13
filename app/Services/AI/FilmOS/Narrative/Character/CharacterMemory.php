<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Character;

/**
 * The memory timeline of a single character — NOT a registry entry.
 *
 * Design contract (mirrors the SceneProjection three-tier semantics):
 *
 *   $profile          LATEST state — who the character is, appearance continuity.
 *   $introducedAt     The ordinal at which the character entered the story.
 *   $emotionTimeline  ACCUMULATED HISTORY keyed by shot ordinal — the emotional arc.
 *                     Never collapsed to "current emotion"; QA reads the raw arc
 *                     to detect implausible jumps (FEAR → JOY with no transition).
 *
 * Memory semantics — the property that makes this a memory, not a log:
 * emotionAt(N) returns the LAST KNOWN emotion at or before ordinal N.
 * A character frightened at shot 1 with no further events is still
 * frightened at shot 3. State persists until changed.
 */
final class CharacterMemory
{
    /**
     * @param array<int, CharacterEmotion> $emotionTimeline keyed by shot ordinal
     */
    public function __construct(
        public readonly CharacterProfile $profile,
        public readonly int              $introducedAt,
        public readonly array            $emotionTimeline = [],
    ) {}

    /**
     * Last known emotion at or before $ordinal — persistence semantics.
     * Returns null only if no emotion was ever recorded at or before $ordinal.
     */
    public function emotionAt(int $ordinal): ?CharacterEmotion
    {
        $best        = null;
        $bestOrdinal = PHP_INT_MIN;

        foreach ($this->emotionTimeline as $entryOrdinal => $emotion) {
            if ($entryOrdinal <= $ordinal && $entryOrdinal > $bestOrdinal) {
                $best        = $emotion;
                $bestOrdinal = $entryOrdinal;
            }
        }

        return $best;
    }

    /**
     * Convenience: the most recent emotion regardless of ordinal.
     * 90% of call sites ask "how is this character feeling now?" — this answers
     * that without requiring the caller to know the last shot ordinal.
     */
    public function latestEmotion(): ?CharacterEmotion
    {
        if ($this->emotionTimeline === []) {
            return null;
        }

        return $this->emotionTimeline[max(array_keys($this->emotionTimeline))];
    }
}
