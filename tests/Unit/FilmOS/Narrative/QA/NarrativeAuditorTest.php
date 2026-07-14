<?php

declare(strict_types=1);

namespace Tests\Unit\FilmOS\Narrative\QA;

use App\Services\AI\FilmOS\Narrative\Bootstrap\NarrativeBootstrapper;
use App\Services\AI\FilmOS\Narrative\Character\CharacterEmotion;
use App\Services\AI\FilmOS\Narrative\Character\CharacterEmotionChangedHandler;
use App\Services\AI\FilmOS\Narrative\Character\CharacterEventFactory;
use App\Services\AI\FilmOS\Narrative\Character\CharacterIntroducedHandler;
use App\Services\AI\FilmOS\Narrative\Character\CharacterProfile;
use App\Services\AI\FilmOS\Narrative\Character\EmotionalState;
use App\Services\AI\FilmOS\Narrative\Character\EmotionIntensity;
use App\Services\AI\FilmOS\Narrative\QA\FindingCategory;
use App\Services\AI\FilmOS\Narrative\QA\FindingSeverity;
use App\Services\AI\FilmOS\Narrative\QA\NarrativeAuditor;
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
use App\Services\AI\FilmOS\Narrative\World\WorldObjectPlacedHandler;
use App\Services\AI\FilmOS\Narrative\World\WorldObjectRemovedHandler;
use App\Services\AI\FilmOS\Planning\GoalNode;
use App\Services\AI\FilmOS\Planning\GoalNodeType;
use PHPUnit\Framework\TestCase;

final class NarrativeAuditorTest extends TestCase
{
    // ── Clean narrative produces empty report ─────────────────────────────────

    public function test_clean_narrative_produces_empty_report(): void
    {
        [$timeline, , $bootstrapper] = $this->buildStack();

        $bootstrapper->bootstrap(worldObjects: [], worldFacts: [], goalNodes: [
            'hook' => $this->leaf('hook', 'Hook'),
        ]);
        $bootstrapper->introduceCharacters([$this->profile('hero')]);
        $bootstrapper->setupScene(
            nodes:     [new SceneNode(id: 'hero_node', type: SceneNodeType::SUBJECT, label: 'Hero')],
            relations: [],
            camera:    $this->camera(focusNodeId: 'hero_node'),
            ordinal:   0,
        );

        $report = $this->auditor()->audit($timeline, $this->project($timeline));

        $this->assertEmpty($report->findings());
        $this->assertTrue($report->isClean());
        $this->assertFalse($report->hasErrors());
        $this->assertFalse($report->hasWarnings());
        $this->assertSame(0, $report->errorCount());
    }

    // ── Findings from multiple rules are aggregated ───────────────────────────

    public function test_multiple_violations_are_aggregated(): void
    {
        [$timeline, , $bootstrapper] = $this->buildStack();

        // Violation 1: emotion for a character never introduced (ERROR)
        $bootstrapper->changeEmotion('ghost', $this->emotion(), ordinal: 0);
        // Violation 2: shot without camera (ERROR)
        $bootstrapper->bootstrap(worldObjects: [], worldFacts: [], goalNodes: [
            'hook' => $this->leaf('hook', 'Hook'),
        ]);
        // Violation 3: camera focusing a node that is not in the scene (WARNING)
        $bootstrapper->setupScene(nodes: [], relations: [], camera: $this->camera(focusNodeId: 'nobody'), ordinal: 5);

        $report = $this->auditor()->audit($timeline, $this->project($timeline));

        $this->assertCount(3, $report->findings());
        $this->assertFalse($report->isClean());
        $this->assertTrue($report->hasErrors());
        $this->assertTrue($report->hasWarnings());
        $this->assertSame(2, $report->errorCount());
        $this->assertSame(1, $report->warningCount());
        $this->assertCount(1, $report->warnings());
    }

    // ── Deterministic order: rule order, then emission order ──────────────────

    public function test_findings_follow_canonical_rule_order(): void
    {
        [$timeline, , $bootstrapper] = $this->buildStack();

        // Camera violation appended to the timeline FIRST…
        $bootstrapper->setupScene(nodes: [], relations: [], camera: $this->camera(focusNodeId: 'nobody'), ordinal: 5);
        // …character violation appended AFTER
        $bootstrapper->changeEmotion('ghost', $this->emotion(), ordinal: 0);

        $report = $this->auditor()->audit($timeline, $this->project($timeline));

        // Report order follows rule order (Character before Camera), not event order
        $this->assertSame(EmotionWithoutIntroductionRule::CODE, $report->findings()[0]->code);
        $this->assertSame(CameraFocusNodeExistsRule::CODE,      $report->findings()[1]->code);
    }

    public function test_same_input_produces_same_report(): void
    {
        [$timeline, , $bootstrapper] = $this->buildStack();

        $bootstrapper->changeEmotion('ghost', $this->emotion(), ordinal: 0);
        $bootstrapper->setupScene(nodes: [], relations: [], camera: $this->camera(focusNodeId: 'nobody'), ordinal: 5);

        $state   = $this->project($timeline);
        $reportA = $this->auditor()->audit($timeline, $state);
        $reportB = $this->auditor()->audit($timeline, $state);

        $this->assertEquals($reportA->findings(), $reportB->findings());
    }

    // ── Report query API ──────────────────────────────────────────────────────

    public function test_report_filters_by_code_category_severity(): void
    {
        [$timeline, , $bootstrapper] = $this->buildStack();

        $bootstrapper->changeEmotion('ghost', $this->emotion(), ordinal: 0);
        $bootstrapper->setupScene(nodes: [], relations: [], camera: $this->camera(focusNodeId: 'nobody'), ordinal: 5);

        $report = $this->auditor()->audit($timeline, $this->project($timeline));

        $this->assertCount(1, $report->byCode(EmotionWithoutIntroductionRule::CODE));
        $this->assertCount(1, $report->byCategory(FindingCategory::CHARACTER));
        $this->assertCount(1, $report->byCategory(FindingCategory::CAMERA));
        $this->assertCount(1, $report->bySeverity(FindingSeverity::ERROR));
        $this->assertCount(1, $report->bySeverity(FindingSeverity::WARNING));
        $this->assertEmpty($report->infos());
    }

    // ── Blocking metadata: impact description, not pipeline decision ─────────

    public function test_missing_camera_is_blocking_but_orphan_emotion_is_not(): void
    {
        [$timeline, , $bootstrapper] = $this->buildStack();

        // Orphan emotion: ERROR but compilable (projection already dropped it)
        $bootstrapper->changeEmotion('ghost', $this->emotion(), ordinal: 0);
        // Missing camera: ERROR and physically uncompilable
        $bootstrapper->bootstrap(worldObjects: [], worldFacts: [], goalNodes: [
            'hook' => $this->leaf('hook', 'Hook'),
        ]);

        $report = $this->auditor()->audit($timeline, $this->project($timeline));

        // Both are ERROR — severity and blocking are orthogonal
        $this->assertSame(2, $report->errorCount());
        $this->assertTrue($report->hasBlocking());
        $this->assertCount(1, $report->blocking());
        $this->assertSame(MissingCameraRule::CODE, $report->blocking()[0]->code);
    }

    public function test_clean_narrative_has_no_blocking_findings(): void
    {
        [$timeline, , $bootstrapper] = $this->buildStack();

        $bootstrapper->bootstrap(worldObjects: [], worldFacts: [], goalNodes: [
            'hook' => $this->leaf('hook', 'Hook'),
        ]);
        $bootstrapper->setupScene(nodes: [], relations: [], camera: $this->camera(), ordinal: 0);

        $report = $this->auditor()->audit($timeline, $this->project($timeline));

        $this->assertFalse($report->hasBlocking());
        $this->assertEmpty($report->blocking());
    }

    public function test_errors_returns_error_findings(): void
    {
        [$timeline, , $bootstrapper] = $this->buildStack();

        $bootstrapper->changeEmotion('ghost', $this->emotion(), ordinal: 0);
        $bootstrapper->setupScene(nodes: [], relations: [], camera: $this->camera(focusNodeId: 'nobody'), ordinal: 5);

        $report = $this->auditor()->audit($timeline, $this->project($timeline));

        $this->assertCount(1, $report->errors());
        $this->assertSame(EmotionWithoutIntroductionRule::CODE, $report->errors()[0]->code);
    }

    // ── Finding carries its ruleId ────────────────────────────────────────────

    public function test_finding_carries_originating_rule_id(): void
    {
        [$timeline, , $bootstrapper] = $this->buildStack();

        $bootstrapper->changeEmotion('ghost', $this->emotion(), ordinal: 0);

        $report = $this->auditor()->audit($timeline, $this->project($timeline));

        $this->assertSame(
            'character.emotion_without_introduction',
            $report->findings()[0]->ruleId,
        );
    }

    // ── Auditing never mutates the timeline ───────────────────────────────────

    public function test_audit_does_not_mutate_timeline(): void
    {
        [$timeline, , $bootstrapper] = $this->buildStack();

        $bootstrapper->changeEmotion('ghost', $this->emotion(), ordinal: 0);
        $before = count(iterator_to_array($timeline->events()));

        $this->auditor()->audit($timeline, $this->project($timeline));

        $this->assertCount($before, iterator_to_array($timeline->events()));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function auditor(): NarrativeAuditor
    {
        // Mirrors the canonical order in FilmOSServiceProvider
        return new NarrativeAuditor(rules: [
            new EmotionWithoutIntroductionRule(),
            new DuplicateIntroductionRule(),
            new DanglingCharacterWorldRefRule(),
            new DanglingSceneWorldRefRule(),
            new MissingCameraRule(),
            new CameraFocusNodeExistsRule(),
        ]);
    }

    /** @return array{InMemorySemanticTimeline, DefaultTimelineProjector, NarrativeBootstrapper} */
    private function buildStack(): array
    {
        $timeline = new InMemorySemanticTimeline();
        $clock    = new SystemClock();

        $bootstrapper = new NarrativeBootstrapper(
            worldFactory:     new WorldEventFactory($clock),
            shotFactory:      new ShotPlannedEventFactory(),
            sceneFactory:     new SceneEventFactory($clock),
            characterFactory: new CharacterEventFactory($clock),
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
