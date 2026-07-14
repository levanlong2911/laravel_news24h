<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Analysis;

use App\Services\AI\FilmOS\Narrative\Character\CharacterEmotion;
use App\Services\AI\FilmOS\Narrative\Scene\CameraConfiguration;
use App\Services\AI\FilmOS\Narrative\Story\StoryBeat;

/**
 * Everything FilmOS DECIDED about one shot — assembled from View contracts only.
 *
 * IDENTITY (frozen with C.8A, phương án B):
 *   $ordinal — the JOIN IDENTITY between knowledge and benchmark outcomes
 *              (BenchmarkResult::$ordinal). Frozen in D1 as shot identity.
 *   $shotId  — Story-domain identifier, kept for trace/debug and human reading.
 *
 * $emotionsByCharacter records the emotion of EVERY character with a known
 * emotion at this ordinal — C.8A does NOT interpret which character the shot
 * is "about" (camera focus ≠ narrative subject: the camera may focus a gun,
 * a door, a ring while the shot is still a character's payoff). Choosing a
 * dominant/protagonist emotion is C.8B/ML territory; the knowledge layer
 * provides the full map so nothing downstream has to guess.
 *
 * Immutable — same rule as StoryShot/WorldObject/NarrativeFinding.
 */
final class ShotKnowledge
{
    /**
     * @param array<string, CharacterEmotion> $emotionsByCharacter characterId → emotion known at this ordinal
     * @param string[]                        $findingCodes        stable QA codes anchored to this shot's ordinal
     */
    public function __construct(
        public readonly int                  $ordinal,
        public readonly string               $shotId,
        public readonly ?StoryBeat           $beat,
        public readonly ?CameraConfiguration $camera,
        public readonly array                $emotionsByCharacter = [],
        public readonly array                $findingCodes        = [],
    ) {}
}
