<?php

declare(strict_types=1);

namespace Tests\Unit\FilmOS\Narrative\Character;

use App\Services\AI\FilmOS\Narrative\Bootstrap\NarrativeBootstrapper;
use App\Services\AI\FilmOS\Narrative\Character\CharacterEmotion;
use App\Services\AI\FilmOS\Narrative\Character\CharacterEmotionChangedHandler;
use App\Services\AI\FilmOS\Narrative\Character\CharacterEventFactory;
use App\Services\AI\FilmOS\Narrative\Character\CharacterIntroducedHandler;
use App\Services\AI\FilmOS\Narrative\Character\CharacterProfile;
use App\Services\AI\FilmOS\Narrative\Character\EmotionalState;
use App\Services\AI\FilmOS\Narrative\Character\EmotionIntensity;
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
use App\Services\AI\FilmOS\Narrative\Timeline\Bridge\ShotPlannedEventFactory;
use App\Services\AI\FilmOS\Narrative\Timeline\Bridge\ShotPlannedProjectionHandler;
use App\Services\AI\FilmOS\Narrative\Timeline\DefaultTimelineProjector;
use App\Services\AI\FilmOS\Narrative\Timeline\InMemorySemanticTimeline;
use App\Services\AI\FilmOS\Narrative\Timeline\SystemClock;
use App\Services\AI\FilmOS\Narrative\Timeline\TimelineOrdinal;
use App\Services\AI\FilmOS\Narrative\Timeline\TimelineRecorder;
use App\Services\AI\FilmOS\Narrative\World\WorldEventFactory;
use App\Services\AI\FilmOS\Narrative\World\WorldFactAssertedHandler;
use App\Services\AI\FilmOS\Narrative\World\WorldObject;
use App\Services\AI\FilmOS\Narrative\World\WorldObjectPlacedHandler;
use App\Services\AI\FilmOS\Narrative\World\WorldObjectRemovedHandler;
use App\Services\AI\FilmOS\Narrative\World\WorldObjectType;
use App\Services\AI\FilmOS\Planning\GoalNode;
use App\Services\AI\FilmOS\Planning\GoalNodeType;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the full D2 pipeline:
 * introduceCharacters()/changeEmotion() → Timeline → Projection → NarrativeState::$characters
 */
final class D2PipelineTest extends TestCase
{
    // ── Invariant: introduced characters appear in projection ─────────────────

    public function test_introduced_characters_appear_in_narrative_state(): void
    {
        [$timeline, $projector, $bootstrapper] = $this->buildStack();

        $bootstrapper->introduceCharacters([
            $this->profile('hero',    ['outfit' => 'black suit']),
            $this->profile('villain', ['outfit' => 'white coat']),
        ]);

        $state = $projector->project($timeline);

        $this->assertTrue($state->characters->hasCharacter('hero'));
        $this->assertTrue($state->characters->hasCharacter('villain'));
        $this->assertCount(2, $state->characters->allCharacters());
        $this->assertSame(
            'black suit',
            $state->characters->memoryOf('hero')?->profile->appearance->getString('outfit'),
        );
    }

    // ── Invariant: baseline introduction has ordinal -1 ───────────────────────

    public function test_baseline_introduction_has_baseline_ordinal(): void
    {
        [$timeline, $projector, $bootstrapper] = $this->buildStack();

        $bootstrapper->introduceCharacters([$this->profile('hero')]);

        $state = $projector->project($timeline);

        $this->assertSame(
            TimelineOrdinal::BASELINE,
            $state->characters->memoryOf('hero')?->introducedAt,
        );
    }

    // ── Invariant: emotional arc — persistence across shots ──────────────────

    public function test_emotion_persists_across_shots_in_full_pipeline(): void
    {
        [$timeline, $projector, $bootstrapper] = $this->buildStack();

        $bootstrapper->bootstrap(worldObjects: [], worldFacts: [], goalNodes: [
            's0' => $this->leaf('s0', 'Shot 0'),
            's1' => $this->leaf('s1', 'Shot 1'),
            's2' => $this->leaf('s2', 'Shot 2'),
            's3' => $this->leaf('s3', 'Shot 3'),
        ]);

        $bootstrapper->introduceCharacters([$this->profile('hero')]);
        $bootstrapper->changeEmotion('hero', $this->emotion(EmotionalState::FEAR, EmotionIntensity::MODERATE), ordinal: 1);

        $state = $projector->project($timeline);

        // No emotion events at shots 2 and 3 — FEAR persists
        $this->assertSame(EmotionalState::FEAR, $state->characters->emotionAt('hero', 2)?->state);
        $this->assertSame(EmotionalState::FEAR, $state->characters->emotionAt('hero', 3)?->state);
        // Before the emotion was set — unknown
        $this->assertNull($state->characters->emotionAt('hero', 0));
    }

    // ── Invariant: emotional progression is preserved as history ─────────────

    public function test_emotion_intensity_progression_is_preserved(): void
    {
        [$timeline, $projector, $bootstrapper] = $this->buildStack();

        $bootstrapper->introduceCharacters([$this->profile('hero')]);
        $bootstrapper->changeEmotion('hero', $this->emotion(EmotionalState::FEAR, EmotionIntensity::MODERATE), ordinal: 1);
        $bootstrapper->changeEmotion('hero', $this->emotion(EmotionalState::FEAR, EmotionIntensity::INTENSE),  ordinal: 2);

        $state  = $projector->project($timeline);
        $memory = $state->characters->memoryOf('hero');

        // QA reads the raw arc: FEAR/MODERATE → FEAR/INTENSE
        $this->assertSame(EmotionIntensity::MODERATE, $memory?->emotionTimeline[1]?->intensity);
        $this->assertSame(EmotionIntensity::INTENSE,  $memory?->emotionTimeline[2]?->intensity);
        // latestEmotion() convenience
        $this->assertSame(EmotionIntensity::INTENSE, $memory?->latestEmotion()?->intensity);
    }

    // ── Invariant: emotion cause flows through the pipeline ──────────────────

    public function test_emotion_cause_flows_through_pipeline(): void
    {
        [$timeline, $projector, $bootstrapper] = $this->buildStack();

        $bootstrapper->introduceCharacters([$this->profile('hero')]);
        $bootstrapper->changeEmotion(
            'hero',
            new CharacterEmotion(EmotionalState::FEAR, EmotionIntensity::INTENSE, cause: 'explosion'),
            ordinal: 1,
        );

        $state = $projector->project($timeline);

        $this->assertSame('explosion', $state->characters->emotionAt('hero', 1)?->cause);
    }

    // ── Invariant: upToOrdinal cuts the emotional arc ─────────────────────────

    public function test_projection_up_to_ordinal_excludes_later_emotions(): void
    {
        [$timeline, $projector, $bootstrapper] = $this->buildStack();

        $bootstrapper->introduceCharacters([$this->profile('hero')]);
        $bootstrapper->changeEmotion('hero', $this->emotion(EmotionalState::FEAR, EmotionIntensity::MODERATE), ordinal: 1);
        $bootstrapper->changeEmotion('hero', $this->emotion(EmotionalState::JOY,  EmotionIntensity::SUBTLE),   ordinal: 3);

        $state = $projector->project($timeline, upToOrdinal: 1);

        // The JOY event at shot 3 is beyond the replay horizon
        $this->assertSame(EmotionalState::FEAR, $state->characters->memoryOf('hero')?->latestEmotion()?->state);
        $this->assertCount(1, $state->characters->memoryOf('hero')?->emotionTimeline ?? []);
    }

    // ── Invariant: worldObjectRef links D2 to D3 by reference only ────────────

    public function test_world_object_ref_cross_references_d3(): void
    {
        [$timeline, $projector, $bootstrapper] = $this->buildStack();

        $bootstrapper->bootstrap(
            worldObjects: [new WorldObject(id: 'hero_obj', type: WorldObjectType::CHARACTER, label: 'Hero', attributes: AttributeBag::empty())],
            worldFacts:   [],
            goalNodes:    [],
        );

        $bootstrapper->introduceCharacters([
            new CharacterProfile(
                id:             'hero',
                label:          'Hero',
                appearance:     AttributeBag::empty(),
                worldObjectRef: 'hero_obj',
            ),
        ]);

        $state = $projector->project($timeline);

        $ref = $state->characters->memoryOf('hero')?->profile->worldObjectRef;
        $this->assertSame('hero_obj', $ref);
        $this->assertTrue($state->world->hasObject($ref), 'cross-reference resolves against D3 world');
    }

    // ── Invariant: all four domains coexist in one projection ─────────────────

    public function test_d0_d3_d4_d2_coexist_in_same_projection(): void
    {
        [$timeline, $projector, $bootstrapper] = $this->buildStack();

        $bootstrapper->bootstrap(
            worldObjects: [new WorldObject(id: 'villa', type: WorldObjectType::LOCATION, label: 'Villa', attributes: AttributeBag::empty())],
            worldFacts:   [],
            goalNodes:    ['s0' => $this->leaf('s0', 'Shot 0')],
        );
        $bootstrapper->introduceCharacters([$this->profile('hero')]);
        $bootstrapper->setupScene(
            nodes:     [new SceneNode(id: 'hero_node', type: SceneNodeType::SUBJECT, label: 'Hero')],
            relations: [],
            camera:    new CameraConfiguration(ShotType::CLOSE_UP, CameraAngle::EYE_LEVEL, CameraMovement::STATIC, LensType::TELEPHOTO),
            ordinal:   0,
        );
        $bootstrapper->changeEmotion('hero', $this->emotion(EmotionalState::DETERMINATION, EmotionIntensity::INTENSE), ordinal: 0);

        $state = $projector->project($timeline);

        $this->assertCount(1, $state->story->allShots());                                            // D0
        $this->assertTrue($state->world->hasObject('villa'));                                   // D3
        $this->assertTrue($state->scene->hasNode('hero_node'));                                 // D4
        $this->assertSame(EmotionalState::DETERMINATION, $state->characters->emotionAt('hero', 0)?->state); // D2
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

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
        ]);

        $bootstrapper = new NarrativeBootstrapper(
            worldFactory:     new WorldEventFactory($clock),
            shotFactory:      new ShotPlannedEventFactory(),
            sceneFactory:     new SceneEventFactory($clock),
            characterFactory: new CharacterEventFactory($clock),
            recorder:         new TimelineRecorder($timeline),
        );

        return [$timeline, $projector, $bootstrapper];
    }

    private function profile(string $id, array $appearance = []): CharacterProfile
    {
        return new CharacterProfile(
            id:         $id,
            label:      ucfirst($id),
            appearance: AttributeBag::from($appearance),
        );
    }

    private function emotion(EmotionalState $state, EmotionIntensity $intensity): CharacterEmotion
    {
        return new CharacterEmotion(state: $state, intensity: $intensity);
    }

    private function leaf(string $id, string $description): GoalNode
    {
        return new GoalNode(id: $id, description: $description, type: GoalNodeType::LEAF, priority: 1.0);
    }
}
