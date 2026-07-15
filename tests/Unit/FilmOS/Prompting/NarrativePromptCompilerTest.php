<?php

declare(strict_types=1);

namespace Tests\Unit\FilmOS\Prompting;

use App\Services\AI\FilmOS\Narrative\Bootstrap\NarrativeBootstrapper;
use App\Services\AI\FilmOS\Narrative\Character\CharacterEmotion;
use App\Services\AI\FilmOS\Narrative\Character\CharacterEmotionChangedHandler;
use App\Services\AI\FilmOS\Narrative\Character\CharacterEventFactory;
use App\Services\AI\FilmOS\Narrative\Production\ConstraintMode;
use App\Services\AI\FilmOS\Narrative\Production\DirectorIntent;
use App\Services\AI\FilmOS\Narrative\Production\EnergyPoint;
use App\Services\AI\FilmOS\Narrative\Production\HeroMoment;
use App\Services\AI\FilmOS\Narrative\Production\MotifImportance;
use App\Services\AI\FilmOS\Narrative\Performance\CharacterPerformance;
use App\Services\AI\FilmOS\Narrative\Performance\PerformanceChannel;
use App\Services\AI\FilmOS\Narrative\Performance\PerformanceCue;
use App\Services\AI\FilmOS\Narrative\Performance\PerformanceDesign;
use App\Services\AI\FilmOS\Narrative\Performance\PerformanceDirectedHandler;
use App\Services\AI\FilmOS\Narrative\Performance\PerformanceEventFactory;
use App\Services\AI\FilmOS\Narrative\Performance\PerformanceIntent;
use App\Services\AI\FilmOS\Narrative\Production\ProductionEventFactory;
use App\Services\AI\FilmOS\Narrative\Production\ProductionPlan;
use App\Services\AI\FilmOS\Narrative\Production\ProductionPlannedHandler;
use App\Services\AI\FilmOS\Narrative\Production\ShotTiming;
use App\Services\AI\FilmOS\Narrative\Production\VisualConstraint;
use App\Services\AI\FilmOS\Narrative\Production\VisualMotif;
use App\Services\AI\FilmOS\Narrative\Character\CharacterIntroducedHandler;
use App\Services\AI\FilmOS\Narrative\Character\CharacterProfile;
use App\Services\AI\FilmOS\Narrative\Character\EmotionalState;
use App\Services\AI\FilmOS\Narrative\Character\EmotionIntensity;
use App\Services\AI\FilmOS\Narrative\QA\NarrativeAuditor;
use App\Services\AI\FilmOS\Narrative\QA\NarrativeAuditReport;
use App\Services\AI\FilmOS\Narrative\QA\Rules\MissingCameraRule;
use App\Services\AI\FilmOS\Narrative\Scene\CameraAngle;
use App\Services\AI\FilmOS\Narrative\Scene\CameraConfiguration;
use App\Services\AI\FilmOS\Narrative\Scene\CameraConfiguredHandler;
use App\Services\AI\FilmOS\Narrative\Scene\CameraMovement;
use App\Services\AI\FilmOS\Narrative\Scene\LensType;
use App\Services\AI\FilmOS\Narrative\Scene\SceneEventFactory;
use App\Services\AI\FilmOS\Narrative\Scene\SceneNodePlacedHandler;
use App\Services\AI\FilmOS\Narrative\Scene\SceneNodeRemovedHandler;
use App\Services\AI\FilmOS\Narrative\Scene\SceneRelationEstablishedHandler;
use App\Services\AI\FilmOS\Narrative\Scene\ShotType;
use App\Services\AI\FilmOS\Narrative\Shared\AttributeBag;
use App\Services\AI\FilmOS\Narrative\Story\EndingFrame;
use App\Services\AI\FilmOS\Narrative\Story\StoryBeat;
use App\Services\AI\FilmOS\Narrative\Timeline\Bridge\ShotPlannedEventFactory;
use App\Services\AI\FilmOS\Narrative\Timeline\Bridge\ShotPlannedProjectionHandler;
use App\Services\AI\FilmOS\Narrative\Timeline\DefaultTimelineProjector;
use App\Services\AI\FilmOS\Narrative\Timeline\InMemorySemanticTimeline;
use App\Services\AI\FilmOS\Narrative\Timeline\SystemClock;
use App\Services\AI\FilmOS\Narrative\Timeline\TimelineRecorder;
use App\Services\AI\FilmOS\Narrative\World\WorldEventFactory;
use App\Services\AI\FilmOS\Narrative\World\WorldFact;
use App\Services\AI\FilmOS\Narrative\World\WorldFactAssertedHandler;
use App\Services\AI\FilmOS\Narrative\World\WorldObjectPlacedHandler;
use App\Services\AI\FilmOS\Narrative\World\WorldObjectRemovedHandler;
use App\Services\AI\FilmOS\Planning\GoalNode;
use App\Services\AI\FilmOS\Planning\GoalNodeType;
use App\Services\AI\FilmOS\Prompting\Compiler\NarrativePromptCompiler;
use App\Services\AI\FilmOS\Prompting\IR\StructuredPrompt;
use PHPUnit\Framework\TestCase;

final class NarrativePromptCompilerTest extends TestCase
{
    // ── Field mapping: knowledge organized into IR, per shot ─────────────────

    public function test_compiles_full_shot_prompt_from_knowledge(): void
    {
        [$timeline, $projector, $bootstrapper] = $this->buildStack();

        $ending = new EndingFrame('Ball disappears into the night sky');
        $bootstrapper->bootstrap(
            worldObjects: [],
            worldFacts:   [
                new WorldFact(key: 'weather', value: 'cold',   assertedAt: -1),
                new WorldFact(key: 'crowd',   value: 'roaring', assertedAt: -1),
            ],
            goalNodes: [
                'shot_payoff' => new GoalNode(
                    id: 'shot_payoff', description: 'Quarterback launches a desperate deep throw',
                    type: GoalNodeType::LEAF, priority: 1.0,
                    beat: StoryBeat::PAYOFF, endingFrame: $ending,
                ),
            ],
        );
        $bootstrapper->introduceCharacters([$this->profile('qb')]);
        $bootstrapper->changeEmotion('qb', new CharacterEmotion(EmotionalState::DETERMINATION, EmotionIntensity::INTENSE), ordinal: 0);
        $bootstrapper->setupScene(nodes: [], relations: [], camera: $this->camera(), ordinal: 0);

        $ir   = $this->compile($timeline, $projector);
        $shot = $ir->shotAt(0);

        $this->assertNotNull($shot);
        $this->assertSame(StoryBeat::PAYOFF, $shot->beat);
        $this->assertSame('Quarterback launches a desperate deep throw', $shot->action);
        $this->assertSame(EmotionalState::DETERMINATION, $shot->emotions['qb']->state);
        $this->assertSame(ShotType::CLOSE_UP, $shot->camera?->shotType);
        $this->assertSame($ending, $shot->endingFrame, 'typed pass-through — exact instance');
        $this->assertTrue($shot->hasEmotions());
    }

    // ── Rule: compiler organizes, never renders language ─────────────────────

    public function test_environment_is_flattened_semantic_not_literary(): void
    {
        [$timeline, $projector, $bootstrapper] = $this->buildStack();

        $bootstrapper->bootstrap(
            worldObjects: [],
            worldFacts:   [new WorldFact(key: 'weather', value: 'cold', assertedAt: -1)],
            goalNodes:    ['s0' => $this->leaf('s0', 'Shot')],
        );
        $bootstrapper->setupScene(nodes: [], relations: [], camera: $this->camera(), ordinal: 0);

        $shot = $this->compile($timeline, $projector)->shotAt(0);

        // Semantic key=>value only — 'weather' => 'cold'. No prose, no vendor phrasing.
        $this->assertSame(['weather' => 'cold'], $shot?->environment->details);
    }

    // ── Blocking gate: uncompilable shots are excluded ────────────────────────

    public function test_shot_with_blocking_finding_is_excluded(): void
    {
        [$timeline, $projector, $bootstrapper] = $this->buildStack();

        $bootstrapper->bootstrap(worldObjects: [], worldFacts: [], goalNodes: [
            'shot_a' => $this->leaf('shot_a', 'Has camera'),
            'shot_b' => $this->leaf('shot_b', 'No camera'),   // → D4.NO_CAMERA (blocking) at ordinal 1
        ]);
        $bootstrapper->setupScene(nodes: [], relations: [], camera: $this->camera(), ordinal: 0);

        $state = $projector->project($timeline);
        $audit = (new NarrativeAuditor([new MissingCameraRule()]))->audit($timeline, $state);
        $ir    = (new NarrativePromptCompiler())->compile(
            $state->story, $state->characters, $state->scene, $state->world, $state->production, $state->performance, $audit,
        );

        $this->assertNotNull($ir->shotAt(0));
        $this->assertNull($ir->shotAt(1), 'blocking finding → physically uncompilable → excluded');
        $this->assertCount(1, $ir->shots());
    }

    // ── Emotion persistence flows into later shots (contract, not inference) ──

    public function test_emotion_persists_into_later_shot_prompts(): void
    {
        [$timeline, $projector, $bootstrapper] = $this->buildStack();

        $bootstrapper->bootstrap(worldObjects: [], worldFacts: [], goalNodes: [
            's0' => $this->leaf('s0', 'First'),
            's1' => $this->leaf('s1', 'Second'),
        ]);
        $bootstrapper->introduceCharacters([$this->profile('hero')]);
        $bootstrapper->changeEmotion('hero', new CharacterEmotion(EmotionalState::FEAR, EmotionIntensity::MODERATE), ordinal: 0);
        $bootstrapper->setupScene(nodes: [], relations: [], camera: $this->camera(), ordinal: 0);
        $bootstrapper->setupScene(nodes: [], relations: [], camera: $this->camera(), ordinal: 1);

        $ir = $this->compile($timeline, $projector);

        $this->assertSame(EmotionalState::FEAR, $ir->shotAt(1)?->emotions['hero']->state);
    }

    // ── Production knowledge is COPIED into IR, never interpreted ────────────

    public function test_production_knowledge_flows_into_ir(): void
    {
        [$timeline, $projector, $bootstrapper] = $this->buildStack();

        $bootstrapper->bootstrap(worldObjects: [], worldFacts: [], goalNodes: [
            's0' => $this->leaf('s0', 'Shot 0'),
        ]);
        $bootstrapper->setupScene(nodes: [], relations: [], camera: $this->camera(), ordinal: 0);
        $bootstrapper->planProduction(new ProductionPlan(
            intent:       new DirectorIntent('Audience must believe all is lost.'),
            motifs:       [new VisualMotif('spiral', MotifImportance::PRIMARY)],
            constraints:  [new VisualConstraint(target: 'ball', rule: 'leaving the frame', mode: ConstraintMode::NEVER)],
            heroMoment:   new HeroMoment(0, 'ball overhead'),
            energyPoints: [new EnergyPoint(0, 90)],
            timings:      [new ShotTiming(0, 2.5)],
        ));

        $ir = $this->compile($timeline, $projector);

        // Production-level — copied semantic knowledge
        $this->assertSame('Audience must believe all is lost.', $ir->directorIntent()?->objective);
        $this->assertSame('spiral', $ir->motifs()[0]->label);
        $this->assertSame(ConstraintMode::NEVER, $ir->constraints()[0]->mode);
        $this->assertSame('ball', $ir->constraints()[0]->target);
        $this->assertSame(0, $ir->heroMoment()?->ordinal);

        // Per-shot — energy copied AS-IS (90 stays 90; "camera shake" is adapter territory)
        $this->assertSame(90,  $ir->shotAt(0)?->energy);
        $this->assertSame(2.5, $ir->shotAt(0)?->durationSeconds);
    }

    // ── Performance (acting) is COPIED into IR — cue order preserved ─────────

    public function test_performance_flows_into_ir_with_cue_order(): void
    {
        [$timeline, $projector, $bootstrapper] = $this->buildStack();

        $bootstrapper->bootstrap(worldObjects: [], worldFacts: [], goalNodes: [
            's0' => $this->leaf('s0', 'The throw'),
        ]);
        $bootstrapper->setupScene(nodes: [], relations: [], camera: $this->camera(), ordinal: 0);
        $bootstrapper->directPerformance(new PerformanceDesign([
            new CharacterPerformance('qb', 0,
                new PerformanceIntent('suppress fear'),
                [
                    new PerformanceCue('holds breath', PerformanceChannel::BREATH),
                    new PerformanceCue('jaw tightens', PerformanceChannel::FACE),
                    new PerformanceCue('commits to the throw'),
                ],
            ),
        ]));

        $shot = $this->compile($timeline, $projector)->shotAt(0);

        $this->assertArrayHasKey('qb', $shot?->performances ?? []);
        $qb = $shot->performances['qb'];
        // Copied semantic knowledge — intent stays intent, cues stay cues, order intact.
        // "He briefly holds his breath, his jaw tightens…" is ADAPTER prose, not IR.
        $this->assertSame('suppress fear', $qb->intent?->intent);
        $this->assertSame(
            ['holds breath', 'jaw tightens', 'commits to the throw'],
            array_map(fn($c) => $c->description, $qb->cues),
        );
    }

    // ── Empty narrative → empty IR ────────────────────────────────────────────

    public function test_empty_narrative_compiles_to_empty_ir(): void
    {
        [$timeline, $projector] = $this->buildStack();

        $ir = $this->compile($timeline, $projector);

        $this->assertTrue($ir->isEmpty());
        $this->assertSame([], $ir->shots());
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function compile(InMemorySemanticTimeline $timeline, DefaultTimelineProjector $projector): StructuredPrompt
    {
        $state = $projector->project($timeline);

        return (new NarrativePromptCompiler())->compile(
            $state->story, $state->characters, $state->scene, $state->world, $state->production, $state->performance,
            new NarrativeAuditReport([]),
        );
    }

    /** @return array{InMemorySemanticTimeline, DefaultTimelineProjector, NarrativeBootstrapper} */
    private function buildStack(): array
    {
        $timeline = new InMemorySemanticTimeline();
        $clock    = new SystemClock();

        $projector = new DefaultTimelineProjector(handlers: [
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

        $bootstrapper = new NarrativeBootstrapper(
            worldFactory:     new WorldEventFactory($clock),
            shotFactory:      new ShotPlannedEventFactory(),
            sceneFactory:     new SceneEventFactory($clock),
            characterFactory:  new CharacterEventFactory($clock),
            productionFactory:  new ProductionEventFactory($clock),
            performanceFactory: new PerformanceEventFactory($clock),
            recorder:         new TimelineRecorder($timeline),
        );

        return [$timeline, $projector, $bootstrapper];
    }

    private function profile(string $id): CharacterProfile
    {
        return new CharacterProfile(id: $id, label: ucfirst($id), appearance: AttributeBag::empty());
    }

    private function leaf(string $id, string $description): GoalNode
    {
        return new GoalNode(id: $id, description: $description, type: GoalNodeType::LEAF, priority: 1.0);
    }

    private function camera(): CameraConfiguration
    {
        return new CameraConfiguration(
            shotType: ShotType::CLOSE_UP,
            angle:    CameraAngle::LOW,
            movement: CameraMovement::TRACKING,
            lens:     LensType::TELEPHOTO,
        );
    }
}
