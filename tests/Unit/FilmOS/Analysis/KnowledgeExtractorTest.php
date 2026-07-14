<?php

declare(strict_types=1);

namespace Tests\Unit\FilmOS\Analysis;

use App\Services\AI\FilmOS\Analysis\KnowledgeExtractor;
use App\Services\AI\FilmOS\Analysis\ShotKnowledge;
use App\Services\AI\FilmOS\Narrative\Bootstrap\NarrativeBootstrapper;
use App\Services\AI\FilmOS\Narrative\Character\CharacterEmotion;
use App\Services\AI\FilmOS\Narrative\Character\CharacterEmotionChangedHandler;
use App\Services\AI\FilmOS\Narrative\Character\CharacterEventFactory;
use App\Services\AI\FilmOS\Narrative\Character\CharacterIntroducedHandler;
use App\Services\AI\FilmOS\Narrative\Character\CharacterProfile;
use App\Services\AI\FilmOS\Narrative\Character\EmotionalState;
use App\Services\AI\FilmOS\Narrative\Character\EmotionIntensity;
use App\Services\AI\FilmOS\Narrative\QA\NarrativeAuditor;
use App\Services\AI\FilmOS\Narrative\QA\NarrativeAuditReport;
use App\Services\AI\FilmOS\Narrative\QA\Rules\CameraFocusNodeExistsRule;
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
use App\Services\AI\FilmOS\Narrative\Story\StoryBeat;
use App\Services\AI\FilmOS\Narrative\Timeline\Bridge\ShotPlannedEventFactory;
use App\Services\AI\FilmOS\Narrative\Timeline\Bridge\ShotPlannedProjectionHandler;
use App\Services\AI\FilmOS\Narrative\Timeline\DefaultTimelineProjector;
use App\Services\AI\FilmOS\Narrative\Timeline\InMemorySemanticTimeline;
use App\Services\AI\FilmOS\Narrative\Timeline\NarrativeState;
use App\Services\AI\FilmOS\Narrative\Timeline\SystemClock;
use App\Services\AI\FilmOS\Narrative\Timeline\TimelineRecorder;
use App\Services\AI\FilmOS\Narrative\World\WorldEventFactory;
use App\Services\AI\FilmOS\Narrative\World\WorldFactAssertedHandler;
use App\Services\AI\FilmOS\Narrative\World\WorldObjectPlacedHandler;
use App\Services\AI\FilmOS\Narrative\World\WorldObjectRemovedHandler;
use App\Services\AI\FilmOS\Planning\GoalNode;
use App\Services\AI\FilmOS\Planning\GoalNodeType;
use PHPUnit\Framework\TestCase;

final class KnowledgeExtractorTest extends TestCase
{
    // ── Happy path: knowledge assembled from views, keyed by ordinal ─────────

    public function test_extracts_beat_camera_and_emotions_keyed_by_ordinal(): void
    {
        [$timeline, $projector, $bootstrapper] = $this->buildStack();

        $bootstrapper->bootstrap(worldObjects: [], worldFacts: [], goalNodes: [
            'shot_hook' => $this->leaf('shot_hook', 'Opening', StoryBeat::HOOK),
        ]);
        $bootstrapper->introduceCharacters([$this->profile('hero'), $this->profile('villain')]);
        $bootstrapper->setupScene(nodes: [], relations: [], camera: $this->camera(), ordinal: 0);
        $bootstrapper->changeEmotion('hero',    new CharacterEmotion(EmotionalState::FEAR, EmotionIntensity::INTENSE),  ordinal: 0);
        $bootstrapper->changeEmotion('villain', new CharacterEmotion(EmotionalState::JOY,  EmotionIntensity::MODERATE), ordinal: 0);

        $knowledge = $this->extract($projector->project($timeline));

        $this->assertArrayHasKey(0, $knowledge, 'keyed by ordinal — the join identity');
        $shot = $knowledge[0];
        $this->assertSame('shot_hook', $shot->shotId);
        $this->assertSame(StoryBeat::HOOK, $shot->beat);
        $this->assertSame(ShotType::CLOSE_UP, $shot->camera?->shotType);

        // Full emotion map — no interpretation of who the shot is "about"
        $this->assertCount(2, $shot->emotionsByCharacter);
        $this->assertSame(EmotionalState::FEAR, $shot->emotionsByCharacter['hero']->state);
        $this->assertSame(EmotionalState::JOY,  $shot->emotionsByCharacter['villain']->state);
    }

    // ── Never-infer: unknown emotion → absent, never defaulted ───────────────

    public function test_character_without_known_emotion_is_absent_from_map(): void
    {
        [$timeline, $projector, $bootstrapper] = $this->buildStack();

        $bootstrapper->bootstrap(worldObjects: [], worldFacts: [], goalNodes: [
            'shot_a' => $this->leaf('shot_a', 'Shot'),
        ]);
        $bootstrapper->introduceCharacters([$this->profile('hero'), $this->profile('silent')]);
        // Only hero gets an emotion — 'silent' has none recorded
        $bootstrapper->changeEmotion('hero', new CharacterEmotion(EmotionalState::ANGER, EmotionIntensity::SUBTLE), ordinal: 0);

        $shot = $this->extract($projector->project($timeline))[0];

        $this->assertArrayHasKey('hero', $shot->emotionsByCharacter);
        $this->assertArrayNotHasKey('silent', $shot->emotionsByCharacter,
            'never-infer: no known emotion → absent from map, not defaulted to NEUTRAL');
    }

    public function test_emotion_persistence_flows_into_later_shots(): void
    {
        [$timeline, $projector, $bootstrapper] = $this->buildStack();

        $bootstrapper->bootstrap(worldObjects: [], worldFacts: [], goalNodes: [
            'shot_a' => $this->leaf('shot_a', 'First'),
            'shot_b' => $this->leaf('shot_b', 'Second'),
        ]);
        $bootstrapper->introduceCharacters([$this->profile('hero')]);
        // Emotion set at shot 0 only — D2 persistence makes it known at shot 1 too
        $bootstrapper->changeEmotion('hero', new CharacterEmotion(EmotionalState::FEAR, EmotionIntensity::MODERATE), ordinal: 0);

        $knowledge = $this->extract($projector->project($timeline));

        $this->assertSame(EmotionalState::FEAR, $knowledge[0]->emotionsByCharacter['hero']->state);
        $this->assertSame(EmotionalState::FEAR, $knowledge[1]->emotionsByCharacter['hero']->state,
            'extractor reads emotionAt() — D2 persistence semantics, not inference');
    }

    // ── QA finding codes anchored to ordinal ─────────────────────────────────

    public function test_finding_codes_are_attached_to_their_shot(): void
    {
        [$timeline, $projector, $bootstrapper] = $this->buildStack();

        $bootstrapper->bootstrap(worldObjects: [], worldFacts: [], goalNodes: [
            'shot_a' => $this->leaf('shot_a', 'Has camera'),
            'shot_b' => $this->leaf('shot_b', 'No camera'),  // → D4.NO_CAMERA at ordinal 1
        ]);
        $bootstrapper->setupScene(nodes: [], relations: [], camera: $this->camera(), ordinal: 0);

        $state     = $projector->project($timeline);
        $audit     = $this->auditor()->audit($timeline, $state);
        $knowledge = (new KnowledgeExtractor())->extract(
            $state->story, $state->scene, $state->characters, $state->world, $audit,
        );

        $this->assertSame([], $knowledge[0]->findingCodes);
        $this->assertSame([MissingCameraRule::CODE], $knowledge[1]->findingCodes);
    }

    // ── Empty narrative → empty knowledge ─────────────────────────────────────

    public function test_empty_story_yields_empty_knowledge(): void
    {
        [$timeline, $projector] = $this->buildStack();

        $this->assertSame([], $this->extract($projector->project($timeline)));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @return array<int, ShotKnowledge> */
    private function extract(NarrativeState $state): array
    {
        $audit = new NarrativeAuditReport([]);  // QA-clean unless the test audits explicitly

        return (new KnowledgeExtractor())->extract(
            $state->story, $state->scene, $state->characters, $state->world, $audit,
        );
    }

    private function auditor(): NarrativeAuditor
    {
        return new NarrativeAuditor(rules: [
            new MissingCameraRule(),
            new CameraFocusNodeExistsRule(),
        ]);
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

    private function profile(string $id): CharacterProfile
    {
        return new CharacterProfile(id: $id, label: ucfirst($id), appearance: AttributeBag::empty());
    }

    private function leaf(string $id, string $description, ?StoryBeat $beat = null): GoalNode
    {
        return new GoalNode(id: $id, description: $description, type: GoalNodeType::LEAF, priority: 1.0, beat: $beat);
    }

    private function camera(): CameraConfiguration
    {
        return new CameraConfiguration(
            shotType: ShotType::CLOSE_UP,
            angle:    CameraAngle::EYE_LEVEL,
            movement: CameraMovement::STATIC,
            lens:     LensType::TELEPHOTO,
        );
    }
}
