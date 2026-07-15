<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Bootstrap;

use App\Services\AI\FilmOS\Narrative\Character\CharacterEmotion;
use App\Services\AI\FilmOS\Narrative\Character\CharacterEventFactory;
use App\Services\AI\FilmOS\Narrative\Character\CharacterProfile;
use App\Services\AI\FilmOS\Narrative\Performance\PerformanceDesign;
use App\Services\AI\FilmOS\Narrative\Performance\PerformanceEventFactory;
use App\Services\AI\FilmOS\Narrative\Production\ProductionEventFactory;
use App\Services\AI\FilmOS\Narrative\Production\ProductionPlan;
use App\Services\AI\FilmOS\Narrative\Scene\CameraConfiguration;
use App\Services\AI\FilmOS\Narrative\Scene\SceneEventFactory;
use App\Services\AI\FilmOS\Narrative\Scene\SceneNode;
use App\Services\AI\FilmOS\Narrative\Scene\SceneRelation;
use App\Services\AI\FilmOS\Narrative\Timeline\Bridge\ShotPlannedEventFactory;
use App\Services\AI\FilmOS\Narrative\Timeline\TimelineOrdinal;
use App\Services\AI\FilmOS\Narrative\Timeline\TimelineRecorder;
use App\Services\AI\FilmOS\Narrative\World\WorldEventFactory;
use App\Services\AI\FilmOS\Narrative\World\WorldFact;
use App\Services\AI\FilmOS\Narrative\World\WorldObject;
use App\Services\AI\FilmOS\Planning\GoalNode;

/**
 * Orchestrates all event factories to populate the Timeline in one call.
 * Callers do not need to know how many factories exist or in what order events are appended.
 *
 * bootstrap() append order:
 *   1. World baseline events  (ordinal -1) — establish world state before any shot
 *   2. Shot planned events    (ordinal 0…N) — shots produced by GoalDecomposer
 *
 * Per-shot layering after bootstrap():
 *   setupScene()          — scene composition for one shot (D4)
 *   introduceCharacters() — characters entering the story (D2)
 *   changeEmotion()       — emotional beat at one shot (D2)
 */
final class NarrativeBootstrapper
{
    public function __construct(
        private readonly WorldEventFactory       $worldFactory,
        private readonly ShotPlannedEventFactory $shotFactory,
        private readonly SceneEventFactory       $sceneFactory,
        private readonly CharacterEventFactory   $characterFactory,
        private readonly ProductionEventFactory  $productionFactory,
        private readonly PerformanceEventFactory  $performanceFactory,
        private readonly TimelineRecorder        $recorder,
    ) {}

    /**
     * @param  WorldObject[]  $worldObjects  objects present in the world at production start
     * @param  WorldFact[]    $worldFacts    facts true about the world at production start
     * @param  GoalNode[]     $goalNodes     keyed by shotId (as produced by GoalDecomposer)
     */
    public function bootstrap(
        array $worldObjects,
        array $worldFacts,
        array $goalNodes,
    ): void {
        $this->recorder->appendMany(
            ...$this->worldFactory->fromWorldContext($worldObjects, $worldFacts),
        );

        $this->recorder->appendMany(
            ...$this->shotFactory->fromGoalNodes($goalNodes),
        );
    }

    /**
     * Records the scene composition for a single shot.
     * Call once per shot after bootstrap() — ordinal must match the shot's GoalNode ordinal.
     *
     * @param  SceneNode[]          $nodes      scene nodes active in this shot
     * @param  SceneRelation[]      $relations  semantic relationships for this shot
     * @param  CameraConfiguration  $camera     camera setup for this shot
     * @param  int                  $ordinal    shot ordinal (0-based)
     */
    public function setupScene(
        array               $nodes,
        array               $relations,
        CameraConfiguration $camera,
        int                 $ordinal,
    ): void {
        $this->recorder->appendMany(
            ...$this->sceneFactory->setupShot($nodes, $relations, $camera, $ordinal),
        );
    }

    /**
     * Introduces characters into the story. Each character must be introduced
     * exactly once per production (see CharacterIntroducedEvent invariant).
     *
     * @param  CharacterProfile[]  $profiles
     * @param  int                 $ordinal  BASELINE for characters present from the start,
     *                                       or the shot ordinal where they first appear
     */
    public function introduceCharacters(
        array $profiles,
        int   $ordinal = TimelineOrdinal::BASELINE,
    ): void {
        $this->recorder->appendMany(
            ...$this->characterFactory->introductions($profiles, $ordinal),
        );
    }

    /**
     * Records an emotional beat for one character at one shot.
     * The emotion persists across subsequent shots until changed again.
     */
    public function changeEmotion(
        string           $characterId,
        CharacterEmotion $emotion,
        int              $ordinal,
    ): void {
        $this->recorder->append(
            $this->characterFactory->emotionChange($characterId, $emotion, $ordinal),
        );
    }

    /**
     * Records the production's staging plan (intent, conflicts, motifs,
     * constraints, hero moment, energy curve, timings). One plan per
     * production — see ProductionPlannedEvent invariant.
     */
    public function planProduction(ProductionPlan $plan, string $productionId = 'default'): void
    {
        $this->recorder->append(
            $this->productionFactory->planned($plan, $productionId),
        );
    }

    /**
     * Records the acting design. One design per production —
     * see PerformanceDirectedEvent invariant.
     */
    public function directPerformance(PerformanceDesign $design, string $productionId = 'default'): void
    {
        $this->recorder->append(
            $this->performanceFactory->directed($design, $productionId),
        );
    }
}
