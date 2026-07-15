<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Benchmark\Scenario;

use App\Services\AI\FilmOS\Narrative\Bootstrap\NarrativeBootstrapper;
use App\Services\AI\FilmOS\Narrative\Character\CharacterEmotion;
use App\Services\AI\FilmOS\Narrative\Character\CharacterEmotionChangedHandler;
use App\Services\AI\FilmOS\Narrative\Character\CharacterEventFactory;
use App\Services\AI\FilmOS\Narrative\Character\CharacterIntroducedHandler;
use App\Services\AI\FilmOS\Narrative\Character\CharacterProfile;
use App\Services\AI\FilmOS\Narrative\Character\EmotionalState;
use App\Services\AI\FilmOS\Narrative\Character\EmotionIntensity;
use App\Services\AI\FilmOS\Narrative\Performance\CharacterPerformance;
use App\Services\AI\FilmOS\Narrative\Performance\PerformanceChannel;
use App\Services\AI\FilmOS\Narrative\Performance\PerformanceCue;
use App\Services\AI\FilmOS\Narrative\Performance\PerformanceDesign;
use App\Services\AI\FilmOS\Narrative\Performance\PerformanceDirectedHandler;
use App\Services\AI\FilmOS\Narrative\Performance\PerformanceEventFactory;
use App\Services\AI\FilmOS\Narrative\Performance\PerformanceIntent;
use App\Services\AI\FilmOS\Narrative\Production\Conflict;
use App\Services\AI\FilmOS\Narrative\Production\ConflictPlan;
use App\Services\AI\FilmOS\Narrative\Production\ConflictType;
use App\Services\AI\FilmOS\Narrative\Production\ConstraintMode;
use App\Services\AI\FilmOS\Narrative\Production\DirectorIntent;
use App\Services\AI\FilmOS\Narrative\Production\EnergyPoint;
use App\Services\AI\FilmOS\Narrative\Production\HeroMoment;
use App\Services\AI\FilmOS\Narrative\Production\MotifImportance;
use App\Services\AI\FilmOS\Narrative\Production\ProductionEventFactory;
use App\Services\AI\FilmOS\Narrative\Production\ProductionPlan;
use App\Services\AI\FilmOS\Narrative\Production\ProductionPlannedHandler;
use App\Services\AI\FilmOS\Narrative\Production\ShotTiming;
use App\Services\AI\FilmOS\Narrative\Production\VisualConstraint;
use App\Services\AI\FilmOS\Narrative\Production\VisualMotif;
use App\Services\AI\FilmOS\Narrative\Scene\CameraAngle;
use App\Services\AI\FilmOS\Narrative\Scene\CameraConfiguration;
use App\Services\AI\FilmOS\Narrative\Scene\CameraConfiguredHandler;
use App\Services\AI\FilmOS\Narrative\Scene\CameraMovement;
use App\Services\AI\FilmOS\Narrative\Scene\LensType;
use App\Services\AI\FilmOS\Narrative\Scene\SceneEventFactory;
use App\Services\AI\FilmOS\Narrative\Scene\SceneNode;
use App\Services\AI\FilmOS\Narrative\Scene\SceneNodePlacedHandler;
use App\Services\AI\FilmOS\Narrative\Scene\SceneNodeRemovedHandler;
use App\Services\AI\FilmOS\Narrative\Scene\SceneNodeType;
use App\Services\AI\FilmOS\Narrative\Scene\SceneRelationEstablishedHandler;
use App\Services\AI\FilmOS\Narrative\Scene\ShotType;
use App\Services\AI\FilmOS\Narrative\Shared\AttributeBag;
use App\Services\AI\FilmOS\Narrative\Story\EndingFrame;
use App\Services\AI\FilmOS\Narrative\Story\StoryBeat;
use App\Services\AI\FilmOS\Narrative\Timeline\Bridge\ShotPlannedEventFactory;
use App\Services\AI\FilmOS\Narrative\Timeline\Bridge\ShotPlannedProjectionHandler;
use App\Services\AI\FilmOS\Narrative\Timeline\Clock;
use App\Services\AI\FilmOS\Narrative\Timeline\DefaultTimelineProjector;
use App\Services\AI\FilmOS\Narrative\Timeline\InMemorySemanticTimeline;
use App\Services\AI\FilmOS\Narrative\Timeline\NarrativeState;
use App\Services\AI\FilmOS\Narrative\Timeline\SystemClock;
use App\Services\AI\FilmOS\Narrative\Timeline\TimelineOrdinal;
use App\Services\AI\FilmOS\Narrative\Timeline\TimelineRecorder;
use App\Services\AI\FilmOS\Narrative\World\WorldEventFactory;
use App\Services\AI\FilmOS\Narrative\World\WorldFact;
use App\Services\AI\FilmOS\Narrative\World\WorldFactAssertedHandler;
use App\Services\AI\FilmOS\Narrative\World\WorldObject;
use App\Services\AI\FilmOS\Narrative\World\WorldObjectPlacedHandler;
use App\Services\AI\FilmOS\Narrative\World\WorldObjectRemovedHandler;
use App\Services\AI\FilmOS\Narrative\World\WorldObjectType;
use App\Services\AI\FilmOS\Planning\BeatOrdinalMap;
use App\Services\AI\FilmOS\Planning\GoalNode;
use App\Services\AI\FilmOS\Planning\GoalNodeType;

/**
 * Turns a validated ScenarioDocument into a NarrativeState by driving
 * NarrativeBootstrapper across all six Knowledge domains.
 *
 * Authored beats are authoritative (SCHEMA rule 2): GoalNodes are built
 * directly from shots{} — MeaningResolver / GoalDecomposer are bypassed
 * (that path is the future derivation benchmark). Ordinals come from a single
 * BeatOrdinalMap, so shot numbering and every beat-keyed translation agree.
 *
 * Absent optional sections skip their Bootstrapper call — no NullProduction,
 * no DefaultPerformance. A fresh timeline is built per assemble() for isolation.
 */
final class ScenarioBootstrapper
{
    public function __construct(
        private readonly Clock $clock = new SystemClock(),
    ) {}

    public function assemble(ScenarioDocument $doc): AssembledScenario
    {
        $beats = BeatOrdinalMap::fromBeats(array_map(
            static fn(string $beat) => StoryBeat::from($beat),
            array_keys($doc->shots),
        ));

        $timeline     = new InMemorySemanticTimeline();
        $recorder     = new TimelineRecorder($timeline);
        $bootstrapper = new NarrativeBootstrapper(
            worldFactory:       new WorldEventFactory($this->clock),
            shotFactory:        new ShotPlannedEventFactory(),
            sceneFactory:       new SceneEventFactory($this->clock),
            characterFactory:   new CharacterEventFactory($this->clock),
            productionFactory:  new ProductionEventFactory($this->clock),
            performanceFactory: new PerformanceEventFactory($this->clock),
            recorder:           $recorder,
        );

        // 1. World + shots (baseline world facts/objects, then shot events 0..N)
        $bootstrapper->bootstrap(
            worldObjects: $this->worldObjects($doc),
            worldFacts:   $this->worldFacts($doc),
            goalNodes:    $this->goalNodes($doc, $beats),
        );

        // 2. Scene composition per shot
        foreach ($beats->orderedBeats() as $beat) {
            $bootstrapper->setupScene(
                nodes:     $this->sceneNodes($doc, $beat),
                relations: [],
                camera:    $this->camera($doc->shots[$beat->value]['camera']),
                ordinal:   $beats->ordinalOf($beat),
            );
        }

        // 3. Characters + emotional arc
        $profiles = $this->characterProfiles($doc);
        if ($profiles !== []) {
            $bootstrapper->introduceCharacters($profiles);
        }
        $this->applyEmotionArc($doc, $beats, $bootstrapper);

        // 4. Optional Production / Performance
        if ($doc->hasProduction()) {
            $bootstrapper->planProduction($this->productionPlan($doc->production, $beats));
        }
        if ($doc->hasPerformance()) {
            $bootstrapper->directPerformance($this->performanceDesign($doc->performance, $beats));
        }

        return new AssembledScenario($timeline, $this->projector()->project($timeline));
    }

    // ── Builders ────────────────────────────────────────────────────────────

    /** @return WorldObject[] */
    private function worldObjects(ScenarioDocument $doc): array
    {
        return array_map(
            fn(array $o) => new WorldObject(
                id:         (string) $o['id'],
                type:       WorldObjectType::from((string) $o['type']),
                label:      (string) ($o['label'] ?? $o['id']),
                attributes: AttributeBag::from((array) ($o['attributes'] ?? [])),
            ),
            $doc->worldObjects,
        );
    }

    /** @return WorldFact[] */
    private function worldFacts(ScenarioDocument $doc): array
    {
        $facts = [];
        foreach ($doc->worldFacts as $key => $value) {
            $facts[] = new WorldFact((string) $key, (string) $value, TimelineOrdinal::BASELINE);
        }
        return $facts;
    }

    /** @return array<string, GoalNode> keyed by shotId, in cinematic order */
    private function goalNodes(ScenarioDocument $doc, BeatOrdinalMap $beats): array
    {
        $nodes = [];
        foreach ($beats->orderedBeats() as $beat) {
            $shot   = $doc->shots[$beat->value];
            $shotId = "shot_{$beat->value}";
            $ending = isset($shot['ending_frame']) ? new EndingFrame((string) $shot['ending_frame']) : null;

            $nodes[$shotId] = new GoalNode(
                id:          $shotId,
                description: (string) $shot['action'],
                type:        GoalNodeType::LEAF,
                priority:    1.0,
                maxShots:    1,
                beat:        $beat,
                endingFrame: $ending,
            );
        }
        return $nodes;
    }

    /** @return SceneNode[] */
    private function sceneNodes(ScenarioDocument $doc, StoryBeat $beat): array
    {
        return array_map(
            fn(array $n) => new SceneNode(
                id:             (string) $n['id'],
                type:           SceneNodeType::from((string) $n['type']),
                label:          (string) ($n['label'] ?? $n['id']),
                worldObjectRef: isset($n['world_object_ref']) ? (string) $n['world_object_ref'] : null,
            ),
            (array) ($doc->sceneNodes[$beat->value] ?? []),
        );
    }

    /** @param array<string, mixed> $cam */
    private function camera(array $cam): CameraConfiguration
    {
        return new CameraConfiguration(
            shotType:    ShotType::from((string) $cam['shot_type']),
            angle:       CameraAngle::from((string) $cam['angle']),
            movement:    CameraMovement::from((string) $cam['movement']),
            lens:        LensType::from((string) $cam['lens']),
            focusNodeId: isset($cam['focus_node']) ? (string) $cam['focus_node'] : null,
        );
    }

    /** @return CharacterProfile[] */
    private function characterProfiles(ScenarioDocument $doc): array
    {
        return array_map(
            fn(array $c) => new CharacterProfile(
                id:             (string) $c['id'],
                label:          (string) ($c['label'] ?? $c['id']),
                appearance:     AttributeBag::from((array) ($c['appearance'] ?? [])),
                worldObjectRef: isset($c['world_object_ref']) ? (string) $c['world_object_ref'] : null,
            ),
            $doc->characters,
        );
    }

    private function applyEmotionArc(ScenarioDocument $doc, BeatOrdinalMap $beats, NarrativeBootstrapper $bootstrapper): void
    {
        foreach ($doc->emotionArc as $characterId => $entries) {
            foreach ((array) $entries as $e) {
                $at      = (string) $e['at'];
                $ordinal = $at === 'baseline' ? TimelineOrdinal::BASELINE : $beats->ordinalOf(StoryBeat::from($at));
                $bootstrapper->changeEmotion(
                    (string) $characterId,
                    new CharacterEmotion(
                        EmotionalState::from((string) $e['state']),
                        EmotionIntensity::from((string) $e['intensity']),
                        isset($e['cause']) ? (string) $e['cause'] : null,
                    ),
                    $ordinal,
                );
            }
        }
    }

    /** @param array<string, mixed> $p */
    private function productionPlan(array $p, BeatOrdinalMap $beats): ProductionPlan
    {
        $conflictPlan = null;
        if (isset($p['conflicts'])) {
            $conflictPlan = new ConflictPlan(array_map(
                fn(array $c) => new Conflict((string) $c['description'], ConflictType::from((string) $c['type'])),
                (array) $p['conflicts'],
            ));
        }

        $hero = null;
        if (isset($p['hero_moment'])) {
            $hero = new HeroMoment(
                $beats->ordinalOf(StoryBeat::from((string) $p['hero_moment']['at'])),
                (string) $p['hero_moment']['description'],
            );
        }

        return new ProductionPlan(
            intent:       isset($p['director_intent']) ? new DirectorIntent((string) $p['director_intent']) : null,
            conflictPlan: $conflictPlan,
            motifs:       array_map(
                fn(array $m) => new VisualMotif((string) $m['label'], MotifImportance::from((string) $m['importance'])),
                (array) ($p['motifs'] ?? []),
            ),
            constraints:  array_map(
                fn(array $c) => new VisualConstraint((string) $c['target'], (string) $c['rule'], ConstraintMode::from((string) $c['mode'])),
                (array) ($p['constraints'] ?? []),
            ),
            heroMoment:   $hero,
            energyPoints: array_map(
                fn(array $e) => new EnergyPoint(
                    $beats->ordinalOf(StoryBeat::from((string) $e['at'])),
                    (int) $e['value'],
                    isset($e['reason']) ? (string) $e['reason'] : null,
                ),
                (array) ($p['energy_curve'] ?? []),
            ),
            timings:      array_map(
                fn(array $t) => new ShotTiming(
                    $beats->ordinalOf(StoryBeat::from((string) $t['at'])),
                    (float) $t['duration_seconds'],
                ),
                (array) ($p['timings'] ?? []),
            ),
        );
    }

    /** @param array<string, mixed> $perf */
    private function performanceDesign(array $perf, BeatOrdinalMap $beats): PerformanceDesign
    {
        $performances = [];
        foreach ($perf as $beat => $byCharacter) {
            $ordinal = $beats->ordinalOf(StoryBeat::from((string) $beat));
            foreach ((array) $byCharacter as $characterId => $dir) {
                $cues = array_map(
                    fn(array $c) => new PerformanceCue(
                        (string) $c['description'],
                        isset($c['channel']) ? PerformanceChannel::from((string) $c['channel']) : null,
                    ),
                    (array) ($dir['cues'] ?? []),
                );
                $performances[] = new CharacterPerformance(
                    characterId: (string) $characterId,
                    ordinal:     $ordinal,
                    intent:      isset($dir['intent'])
                        ? new PerformanceIntent((string) $dir['intent'], isset($dir['motivation']) ? (string) $dir['motivation'] : null)
                        : null,
                    cues:        $cues,
                );
            }
        }
        return new PerformanceDesign($performances);
    }

    private function projector(): DefaultTimelineProjector
    {
        return new DefaultTimelineProjector(handlers: [
            new ShotPlannedProjectionHandler(),
            new WorldObjectPlacedHandler(),
            new WorldObjectRemovedHandler(),
            new WorldFactAssertedHandler(),
            new CharacterIntroducedHandler(),
            new CharacterEmotionChangedHandler(),
            new SceneNodePlacedHandler(),
            new SceneNodeRemovedHandler(),
            new SceneRelationEstablishedHandler(),
            new CameraConfiguredHandler(),
            new ProductionPlannedHandler(),
            new PerformanceDirectedHandler(),
        ]);
    }
}
