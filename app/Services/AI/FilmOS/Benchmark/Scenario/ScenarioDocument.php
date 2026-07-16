<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Benchmark\Scenario;

use App\Services\AI\FilmOS\Prompting\IR\VisualStyle;

/**
 * A parsed, VALIDATED, immutable representation of one scenario file.
 *
 * Metadata is typed (Suite/Difficulty enums, scalars) for the catalog tests.
 * Cinematic sections are held as the validated decoded arrays — beat-keyed,
 * exactly as authored — because their only consumer (ScenarioBootstrapper)
 * builds the real Knowledge-domain objects from them. Re-typing every nested
 * structure into parallel DTOs would duplicate the domain model with no
 * consumer that needs it.
 *
 * Everything here is guaranteed valid by ScenarioLoader: enum strings resolve,
 * references are intact, rule 8 holds. Consumers may trust it without re-checking.
 */
final class ScenarioDocument
{
    /**
     * @param string[]              $secondaryLearningDimensions
     * @param string[]              $stressDimensions
     * @param array<int, mixed>     $facts
     * @param array<int, mixed>     $worldObjects
     * @param array<string, string> $worldFacts
     * @param array<int, mixed>     $characters
     * @param array<string, mixed>  $emotionArc      characterId => entries[]
     * @param array<string, mixed>  $shots           beat => shot
     * @param array<string, mixed>  $sceneNodes      beat => node[]
     * @param array<string, mixed>|null $production  null when the scenario has no production section
     * @param array<string, mixed>|null $performance null when the scenario has no performance section
     */
    public function __construct(
        public readonly int        $schemaVersion,
        public readonly string     $id,
        public readonly Suite      $suite,
        public readonly string     $level,
        public readonly Difficulty $difficulty,
        public readonly int        $durationSeconds,
        public readonly string     $primaryLearningDimension,
        public readonly array      $secondaryLearningDimensions,
        public readonly array      $stressDimensions,
        public readonly string     $goal,
        /** The look this piece is shot in; null = the renderer's default. */
        public readonly ?VisualStyle $visualStyle,
        public readonly array      $facts,
        public readonly array      $worldObjects,
        public readonly array      $worldFacts,
        public readonly array      $characters,
        public readonly array      $emotionArc,
        public readonly array      $shots,
        public readonly array      $sceneNodes,
        public readonly ?array     $production,
        public readonly ?array     $performance,
    ) {}

    public function hasProduction(): bool
    {
        return $this->production !== null;
    }

    public function hasPerformance(): bool
    {
        return $this->performance !== null;
    }
}
