<?php

declare(strict_types=1);

namespace Tests\Unit\FilmOS\Narrative\QA;

use App\Services\AI\FilmOS\Narrative\Bootstrap\NarrativeBootstrapper;
use App\Services\AI\FilmOS\Narrative\Character\CharacterEmotion;
use App\Services\AI\FilmOS\Narrative\Character\CharacterEmotionChangedHandler;
use App\Services\AI\FilmOS\Narrative\Character\CharacterEventFactory;
use App\Services\AI\FilmOS\Narrative\Production\ProductionEventFactory;
use App\Services\AI\FilmOS\Narrative\Character\CharacterIntroducedHandler;
use App\Services\AI\FilmOS\Narrative\Character\CharacterProfile;
use App\Services\AI\FilmOS\Narrative\Character\EmotionalState;
use App\Services\AI\FilmOS\Narrative\Character\EmotionIntensity;
use App\Services\AI\FilmOS\Narrative\QA\FindingSeverity;
use App\Services\AI\FilmOS\Narrative\QA\NarrativeAuditContext;
use App\Services\AI\FilmOS\Narrative\QA\Rules\CameraFocusNodeExistsRule;
use App\Services\AI\FilmOS\Narrative\QA\Rules\DanglingCharacterWorldRefRule;
use App\Services\AI\FilmOS\Narrative\QA\Rules\DanglingSceneWorldRefRule;
use App\Services\AI\FilmOS\Narrative\QA\Rules\DuplicateIntroductionRule;
use App\Services\AI\FilmOS\Narrative\QA\Rules\EmotionWithoutIntroductionRule;
use App\Services\AI\FilmOS\Narrative\QA\Rules\MissingCameraRule;
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
use App\Services\AI\FilmOS\Narrative\Timeline\NarrativeState;
use App\Services\AI\FilmOS\Narrative\Timeline\SystemClock;
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

final class NarrativeRulesTest extends TestCase
{
    // ── EmotionWithoutIntroductionRule ────────────────────────────────────────

    public function test_emotion_without_introduction_is_flagged(): void
    {
        [$timeline, , $bootstrapper] = $this->buildStack();

        // Emotion for 'ghost' — never introduced. Projection drops it; timeline keeps it.
        $bootstrapper->changeEmotion('ghost', $this->emotion(), ordinal: 1);

        $findings = iterator_to_array((new EmotionWithoutIntroductionRule())->check($this->ctx($timeline)), false);

        $this->assertCount(1, $findings);
        $this->assertSame(EmotionWithoutIntroductionRule::CODE, $findings[0]->code);
        $this->assertSame(FindingSeverity::ERROR, $findings[0]->severity);
        $this->assertSame('ghost', $findings[0]->subjectId);
        $this->assertSame(1, $findings[0]->ordinal);
    }

    public function test_emotion_with_introduction_is_clean(): void
    {
        [$timeline, , $bootstrapper] = $this->buildStack();

        $bootstrapper->introduceCharacters([$this->profile('hero')]);
        $bootstrapper->changeEmotion('hero', $this->emotion(), ordinal: 1);

        $findings = iterator_to_array((new EmotionWithoutIntroductionRule())->check($this->ctx($timeline)), false);

        $this->assertEmpty($findings);
    }

    // ── DuplicateIntroductionRule ─────────────────────────────────────────────

    public function test_duplicate_introduction_is_flagged(): void
    {
        [$timeline, , $bootstrapper] = $this->buildStack();

        $bootstrapper->introduceCharacters([$this->profile('hero')]);
        $bootstrapper->introduceCharacters([$this->profile('hero')], ordinal: 2);

        $findings = iterator_to_array((new DuplicateIntroductionRule())->check($this->ctx($timeline)), false);

        $this->assertCount(1, $findings);
        $this->assertSame(DuplicateIntroductionRule::CODE, $findings[0]->code);
        $this->assertSame(FindingSeverity::WARNING, $findings[0]->severity);
        $this->assertSame('hero', $findings[0]->subjectId);
    }

    public function test_single_introduction_is_clean(): void
    {
        [$timeline, , $bootstrapper] = $this->buildStack();

        $bootstrapper->introduceCharacters([$this->profile('hero'), $this->profile('villain')]);

        $findings = iterator_to_array((new DuplicateIntroductionRule())->check($this->ctx($timeline)), false);

        $this->assertEmpty($findings);
    }

    // ── DanglingCharacterWorldRefRule ─────────────────────────────────────────

    public function test_dangling_character_world_ref_is_flagged(): void
    {
        [$timeline, , $bootstrapper] = $this->buildStack();

        $bootstrapper->introduceCharacters([
            new CharacterProfile(id: 'hero', label: 'Hero', appearance: AttributeBag::empty(), worldObjectRef: 'missing_obj'),
        ]);

        $findings = iterator_to_array((new DanglingCharacterWorldRefRule())->check($this->ctx($timeline)), false);

        $this->assertCount(1, $findings);
        $this->assertSame(DanglingCharacterWorldRefRule::CODE, $findings[0]->code);
        $this->assertSame('hero', $findings[0]->subjectId);
    }

    public function test_resolving_character_world_ref_is_clean(): void
    {
        [$timeline, , $bootstrapper] = $this->buildStack();

        $bootstrapper->bootstrap(
            worldObjects: [new WorldObject(id: 'hero_obj', type: WorldObjectType::CHARACTER, label: 'Hero', attributes: AttributeBag::empty())],
            worldFacts:   [],
            goalNodes:    [],
        );
        $bootstrapper->introduceCharacters([
            new CharacterProfile(id: 'hero', label: 'Hero', appearance: AttributeBag::empty(), worldObjectRef: 'hero_obj'),
        ]);

        $findings = iterator_to_array((new DanglingCharacterWorldRefRule())->check($this->ctx($timeline)), false);

        $this->assertEmpty($findings);
    }

    // ── DanglingSceneWorldRefRule ─────────────────────────────────────────────

    public function test_dangling_scene_world_ref_is_flagged(): void
    {
        [$timeline, , $bootstrapper] = $this->buildStack();

        $bootstrapper->setupScene(
            nodes:     [new SceneNode(id: 'hero_node', type: SceneNodeType::SUBJECT, label: 'Hero', worldObjectRef: 'missing_obj')],
            relations: [],
            camera:    $this->camera(),
            ordinal:   0,
        );

        $findings = iterator_to_array((new DanglingSceneWorldRefRule())->check($this->ctx($timeline)), false);

        $this->assertCount(1, $findings);
        $this->assertSame(DanglingSceneWorldRefRule::CODE, $findings[0]->code);
        $this->assertSame('hero_node', $findings[0]->subjectId);
    }

    public function test_scene_node_without_world_ref_is_clean(): void
    {
        [$timeline, , $bootstrapper] = $this->buildStack();

        $bootstrapper->setupScene(
            nodes:     [new SceneNode(id: 'hero_node', type: SceneNodeType::SUBJECT, label: 'Hero')],
            relations: [],
            camera:    $this->camera(),
            ordinal:   0,
        );

        $findings = iterator_to_array((new DanglingSceneWorldRefRule())->check($this->ctx($timeline)), false);

        $this->assertEmpty($findings);
    }

    // ── MissingCameraRule ─────────────────────────────────────────────────────

    public function test_shot_without_camera_is_flagged(): void
    {
        [$timeline, , $bootstrapper] = $this->buildStack();

        $bootstrapper->bootstrap(worldObjects: [], worldFacts: [], goalNodes: [
            'hook' => $this->leaf('hook', 'Hook'),
            'cta'  => $this->leaf('cta',  'CTA'),
        ]);
        // Camera only for shot 0 — shot 1 is uncompilable
        $bootstrapper->setupScene(nodes: [], relations: [], camera: $this->camera(), ordinal: 0);

        $findings = iterator_to_array((new MissingCameraRule())->check($this->ctx($timeline)), false);

        $this->assertCount(1, $findings);
        $this->assertSame(MissingCameraRule::CODE, $findings[0]->code);
        $this->assertSame(FindingSeverity::ERROR, $findings[0]->severity);
        $this->assertSame('cta', $findings[0]->subjectId);
        $this->assertSame(1, $findings[0]->ordinal);
    }

    public function test_all_shots_with_cameras_is_clean(): void
    {
        [$timeline, , $bootstrapper] = $this->buildStack();

        $bootstrapper->bootstrap(worldObjects: [], worldFacts: [], goalNodes: [
            'hook' => $this->leaf('hook', 'Hook'),
        ]);
        $bootstrapper->setupScene(nodes: [], relations: [], camera: $this->camera(), ordinal: 0);

        $findings = iterator_to_array((new MissingCameraRule())->check($this->ctx($timeline)), false);

        $this->assertEmpty($findings);
    }

    // ── CameraFocusNodeExistsRule ─────────────────────────────────────────────

    public function test_camera_focusing_missing_node_is_flagged(): void
    {
        [$timeline, , $bootstrapper] = $this->buildStack();

        $bootstrapper->setupScene(
            nodes:     [],
            relations: [],
            camera:    $this->camera(focusNodeId: 'nobody'),
            ordinal:   0,
        );

        $findings = iterator_to_array((new CameraFocusNodeExistsRule())->check($this->ctx($timeline)), false);

        $this->assertCount(1, $findings);
        $this->assertSame(CameraFocusNodeExistsRule::CODE, $findings[0]->code);
        $this->assertSame(FindingSeverity::WARNING, $findings[0]->severity);
        $this->assertSame('nobody', $findings[0]->subjectId);
        $this->assertSame(0, $findings[0]->ordinal);
    }

    public function test_camera_focusing_existing_node_is_clean(): void
    {
        [$timeline, , $bootstrapper] = $this->buildStack();

        $bootstrapper->setupScene(
            nodes:     [new SceneNode(id: 'hero_node', type: SceneNodeType::SUBJECT, label: 'Hero')],
            relations: [],
            camera:    $this->camera(focusNodeId: 'hero_node'),
            ordinal:   0,
        );

        $findings = iterator_to_array((new CameraFocusNodeExistsRule())->check($this->ctx($timeline)), false);

        $this->assertEmpty($findings);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @return array{InMemorySemanticTimeline, DefaultTimelineProjector, NarrativeBootstrapper} */
    private function buildStack(): array
    {
        $timeline = new InMemorySemanticTimeline();
        $clock    = new SystemClock();

        $bootstrapper = new NarrativeBootstrapper(
            worldFactory:     new WorldEventFactory($clock),
            shotFactory:      new ShotPlannedEventFactory(),
            sceneFactory:     new SceneEventFactory($clock),
            characterFactory:  new CharacterEventFactory($clock),
            productionFactory: new ProductionEventFactory($clock),
            recorder:         new TimelineRecorder($timeline),
        );

        return [$timeline, $this->projector(), $bootstrapper];
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
        ]);
    }

    private function project(InMemorySemanticTimeline $timeline): NarrativeState
    {
        return $this->projector()->project($timeline);
    }

    private function ctx(InMemorySemanticTimeline $timeline): NarrativeAuditContext
    {
        return new NarrativeAuditContext($timeline, $this->project($timeline));
    }

    private function profile(string $id): CharacterProfile
    {
        return new CharacterProfile(id: $id, label: ucfirst($id), appearance: AttributeBag::empty());
    }

    private function emotion(): CharacterEmotion
    {
        return new CharacterEmotion(EmotionalState::FEAR, EmotionIntensity::MODERATE);
    }

    private function camera(?string $focusNodeId = null): CameraConfiguration
    {
        return new CameraConfiguration(
            shotType:    ShotType::MEDIUM,
            angle:       CameraAngle::EYE_LEVEL,
            movement:    CameraMovement::STATIC,
            lens:        LensType::NORMAL,
            focusNodeId: $focusNodeId,
        );
    }

    private function leaf(string $id, string $description): GoalNode
    {
        return new GoalNode(id: $id, description: $description, type: GoalNodeType::LEAF, priority: 1.0);
    }
}
