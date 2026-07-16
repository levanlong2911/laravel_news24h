<?php

declare(strict_types=1);

namespace Tests\Unit\FilmOS\Prompting;

use App\Services\AI\FilmOS\Narrative\Character\CharacterEmotion;
use App\Services\AI\FilmOS\Narrative\Character\EmotionalState;
use App\Services\AI\FilmOS\Narrative\Character\EmotionIntensity;
use App\Services\AI\FilmOS\Narrative\Performance\CharacterPerformance;
use App\Services\AI\FilmOS\Narrative\Performance\PerformanceChannel;
use App\Services\AI\FilmOS\Narrative\Performance\PerformanceCue;
use App\Services\AI\FilmOS\Narrative\Production\Conflict;
use App\Services\AI\FilmOS\Narrative\Production\ConflictType;
use App\Services\AI\FilmOS\Narrative\Production\ConstraintMode;
use App\Services\AI\FilmOS\Narrative\Production\MotifImportance;
use App\Services\AI\FilmOS\Narrative\Production\VisualConstraint;
use App\Services\AI\FilmOS\Narrative\Production\VisualMotif;
use App\Services\AI\FilmOS\Narrative\Scene\CameraAngle;
use App\Services\AI\FilmOS\Narrative\Scene\CameraConfiguration;
use App\Services\AI\FilmOS\Narrative\Scene\CameraMovement;
use App\Services\AI\FilmOS\Narrative\Scene\LensType;
use App\Services\AI\FilmOS\Narrative\Scene\ShotType;
use App\Services\AI\FilmOS\Narrative\Shared\AttributeBag;
use App\Services\AI\FilmOS\Narrative\World\WorldObjectType;
use App\Services\AI\FilmOS\Prompting\Adapter\KlingPromptRenderer;
use App\Services\AI\FilmOS\Prompting\Adapter\ProviderId;
use App\Services\AI\FilmOS\Prompting\IR\PromptEnvironment;
use App\Services\AI\FilmOS\Prompting\IR\ShotPrompt;
use App\Services\AI\FilmOS\Prompting\IR\KeyVisual;
use App\Services\AI\FilmOS\Prompting\IR\StructuredPrompt;
use App\Services\AI\FilmOS\Prompting\IR\SubjectDescriptor;
use App\Services\AI\FilmOS\Prompting\IR\VisualRelevance;
use PHPUnit\Framework\TestCase;

/**
 * KlingPromptRenderer is where Kling wording lives. Tests assert the MAPPING
 * (typed knowledge → phrase), not exact prose, so wording stays tunable.
 */
final class KlingPromptRendererTest extends TestCase
{
    public function test_provider_is_kling(): void
    {
        $this->assertSame(ProviderId::KLING, (new KlingPromptRenderer())->provider());
    }

    public function test_anatomy_guard_reflects_subject_type(): void
    {
        $vehicle = $this->render($this->promptWithSubject(WorldObjectType::VEHICLE, 'Supercar'));
        $this->assertStringContainsString('no human figures', $vehicle->positive);

        $animal = $this->render($this->promptWithSubject(WorldObjectType::ANIMAL, 'Stallion'));
        $this->assertStringContainsString('animal anatomy', $animal->positive);

        $human = $this->render($this->promptWithSubject(WorldObjectType::CHARACTER, 'Hero'));
        $this->assertStringContainsString('human anatomy', $human->positive);
    }

    public function test_camera_and_lens_are_phrased(): void
    {
        $out = $this->render($this->fullPrompt());

        $this->assertStringContainsStringIgnoringCase('close-up', $out->positive);
        $this->assertStringContainsString('85mm', $out->positive);   // compact tag, not "telephoto compression"
    }

    public function test_emotion_renders_as_facial_behaviour_on_a_close_shot(): void
    {
        $out = $this->render($this->fullPrompt());   // hook is a close-up

        // Emotion is rendered as observable behaviour a camera sees, not the label.
        $this->assertStringContainsString('jaw tight', $out->positive);
        $this->assertStringNotContainsString('fearful', $out->positive);
    }

    public function test_emotion_is_dropped_on_a_distant_shot(): void
    {
        // Emotion does not read at wide distance, so it must not be rendered there.
        $wide = new ShotPrompt(
            ordinal: 0, beat: null, action: 'He stands downfield',
            emotions: ['h' => new CharacterEmotion(EmotionalState::FEAR, EmotionIntensity::INTENSE)],
            camera: new CameraConfiguration(ShotType::WIDE, CameraAngle::LOW, CameraMovement::STATIC, LensType::WIDE),
            environment: new PromptEnvironment(),
        );
        $out = $this->render(new StructuredPrompt(
            shots:    [0 => $wide],
            subjects: [$this->subject(WorldObjectType::CHARACTER, 'Hero')],
        ));

        $this->assertStringNotContainsString('jaw tight', $out->positive);
    }

    public function test_filmable_conflict_surfaces_and_abstract_conflict_drops(): void
    {
        $out = $this->render($this->fullPrompt());

        $this->assertStringContainsStringIgnoringCase('pocket collapsing', $out->positive);   // PHYSICAL — filmable
        $this->assertStringNotContainsString('clock running out', $out->positive);            // TIME — abstract, dropped
    }

    public function test_performance_keeps_a_gross_motor_cue_and_caps_at_one_per_beat(): void
    {
        // GAZE is dropped (Kling can't render micro-expression); the first
        // gross-motor cue survives; any further cue is capped out to stay short.
        $out = $this->render($this->beatWithCues([
            new PerformanceCue('eyes flick left', PerformanceChannel::GAZE),
            new PerformanceCue('grip tightens on the ball', PerformanceChannel::HANDS),
            new PerformanceCue('shoulders square', PerformanceChannel::POSTURE),
        ]));

        $this->assertStringNotContainsStringIgnoringCase('eyes flick', $out->positive, 'GAZE cue must be dropped');
        $this->assertStringContainsStringIgnoringCase('grip tightens on the ball', $out->positive, 'first gross-motor cue kept');
        $this->assertStringNotContainsStringIgnoringCase('shoulders square', $out->positive, 'at most one cue per beat');
    }

    public function test_breath_and_face_cues_are_dropped(): void
    {
        $out = $this->render($this->beatWithCues([
            new PerformanceCue('half breath', PerformanceChannel::BREATH),
            new PerformanceCue('jaw tightens', PerformanceChannel::FACE),
        ]));

        $this->assertStringNotContainsStringIgnoringCase('half breath', $out->positive);
        $this->assertStringNotContainsStringIgnoringCase('jaw tightens', $out->positive);
    }

    public function test_untagged_cue_is_kept(): void
    {
        // A cue with no channel ("full-body release") is gross-motor by nature — keep it.
        $out = $this->render($this->beatWithCues([
            new PerformanceCue('full-body release into the throw'),
        ]));

        $this->assertStringContainsStringIgnoringCase('full-body release into the throw', $out->positive);
    }

    public function test_never_constraints_become_negative_prompt(): void
    {
        $out = $this->render($this->fullPrompt());

        $this->assertNotNull($out->negative);
        $this->assertStringContainsString('crowd blocking the hero', $out->negative);
        $this->assertStringContainsString('extra limbs', $out->negative);   // standard guard
    }

    public function test_always_constraints_reinforce_the_positive_prompt(): void
    {
        $out = $this->render($this->fullPrompt());

        // ALWAYS constraint must appear in positive, never negative.
        $this->assertStringContainsStringIgnoringCase('keep the football visible after release', $out->positive);
        $this->assertStringNotContainsString('football visible after release', (string) $out->negative);
    }

    public function test_hero_moment_is_its_own_final_shot_line(): void
    {
        $out = $this->render($this->fullPrompt());

        // Hero moment gets emphasis as its own line, not buried in the style tail.
        $this->assertStringContainsString('FINAL SHOT', $out->positive);
        $this->assertStringContainsStringIgnoringCase('ball overhead', $out->positive);
    }

    public function test_motifs_appear_in_a_visual_language_line(): void
    {
        $out = $this->render($this->fullPrompt());

        $this->assertStringContainsString('VISUAL LANGUAGE', $out->positive);
        $this->assertStringContainsString('spiral', $out->positive);
    }

    public function test_metadata_carries_duration_and_energy_peak(): void
    {
        $out = $this->render($this->fullPrompt());

        $this->assertSame('kling', $out->metadata['provider']);
        $this->assertSame(100, $out->metadata['energy_peak']);
        // Clip length is the scenario's authored duration, read by the render
        // command from the document — it is not the prompt's business.
        $this->assertArrayNotHasKey('duration_seconds', $out->metadata);
    }

    public function test_key_visuals_from_article_are_rendered(): void
    {
        // facts[].visual_hint → KEY VISUALS block (compiler ranks; renderer phrases).
        $out = $this->render(new StructuredPrompt(
            shots:      [0 => $this->minimalShot()],
            subjects:   [$this->subject(WorldObjectType::CHARACTER, 'Hero')],
            keyVisuals: [
                new KeyVisual('two defenders converging', VisualRelevance::HIGH),
                new KeyVisual('lone figure downfield', VisualRelevance::MEDIUM),
            ],
        ));

        $this->assertStringContainsString('KEY VISUALS', $out->positive);
        $this->assertStringContainsStringIgnoringCase('two defenders converging', $out->positive);
        $this->assertStringContainsStringIgnoringCase('lone figure downfield', $out->positive);
    }

    public function test_subject_renders_authored_appearance_over_bare_attributes(): void
    {
        $subject = new SubjectDescriptor(
            'qb', WorldObjectType::CHARACTER, 'Quarterback', AttributeBag::from(['team' => 'red']),
            isPrimary: true, appearance: ['outfit' => 'red jersey number 12, white helmet', 'build' => 'tall athletic'],
        );
        $out = $this->render(new StructuredPrompt(shots: [0 => $this->minimalShot()], subjects: [$subject]));

        $this->assertStringContainsString('red jersey number 12, white helmet', $out->positive);
        $this->assertStringContainsString('tall athletic', $out->positive);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function minimalShot(): ShotPrompt
    {
        return new ShotPrompt(
            ordinal: 0, beat: null, action: 'The subject moves',
            emotions: [], camera: null, environment: new PromptEnvironment(),
        );
    }

    /**
     * The pipeline under test is planner → renderer: the planner decides what is
     * allowed in, the renderer only words it. Tests assert the MAPPING
     * (typed knowledge → phrase kind), never exact prose.
     */
    private function render(StructuredPrompt $p): \App\Services\AI\FilmOS\Prompting\Adapter\RenderedPrompt
    {
        return (new KlingPromptRenderer())->render(
            (new \App\Services\AI\FilmOS\Prompting\Plan\RenderPlanner())->plan($p),
        );
    }

    private function subject(WorldObjectType $type, string $label): SubjectDescriptor
    {
        return new SubjectDescriptor('obj', $type, $label, AttributeBag::empty(), isPrimary: true);
    }

    /** @param PerformanceCue[] $cues */
    private function beatWithCues(array $cues): StructuredPrompt
    {
        $shot = new ShotPrompt(
            ordinal: 0, beat: null, action: 'The hero acts',
            emotions: [], camera: null, environment: new PromptEnvironment(),
            performances: ['hero' => new CharacterPerformance('hero', 0, null, $cues)],
        );
        return new StructuredPrompt(
            shots:    [0 => $shot],
            subjects: [$this->subject(WorldObjectType::CHARACTER, 'Hero')],
        );
    }

    private function promptWithSubject(WorldObjectType $type, string $label): StructuredPrompt
    {
        $shot = new ShotPrompt(
            ordinal: 0, beat: null, action: 'The subject moves',
            emotions: [], camera: null, environment: new PromptEnvironment(),
        );
        return new StructuredPrompt(shots: [0 => $shot], subjects: [$this->subject($type, $label)]);
    }

    private function fullPrompt(): StructuredPrompt
    {
        $hook = new ShotPrompt(
            ordinal:  0,
            beat:     null,
            action:   'The hero reads the danger',
            emotions: ['hero' => new CharacterEmotion(EmotionalState::FEAR, EmotionIntensity::INTENSE)],
            camera:   new CameraConfiguration(ShotType::CLOSE_UP, CameraAngle::EYE_LEVEL, CameraMovement::HANDHELD, LensType::TELEPHOTO, 'hero_node'),
            environment: new PromptEnvironment(['weather' => 'cold']),
            durationSeconds: 2.0,
            energy: 40,
            performances: [
                'hero' => new CharacterPerformance('hero', 0, null, [
                    new PerformanceCue('holds breath'),
                    new PerformanceCue('jaw tightens'),
                ]),
            ],
        );
        $payoff = new ShotPrompt(
            ordinal:  1,
            beat:     null,
            action:   'The hero commits',
            emotions: [],
            camera:   new CameraConfiguration(ShotType::WIDE, CameraAngle::LOW, CameraMovement::TILT, LensType::WIDE),
            environment: new PromptEnvironment(),
            durationSeconds: 2.5,
            energy: 100,
        );

        return new StructuredPrompt(
            shots:          [0 => $hook, 1 => $payoff],
            subjects:       [$this->subject(WorldObjectType::CHARACTER, 'Hero')],
            motifs:         [new VisualMotif('spiral', MotifImportance::PRIMARY)],
            constraints:    [
                new VisualConstraint('crowd', 'blocking the hero', ConstraintMode::NEVER),
                new VisualConstraint('football', 'visible after release', ConstraintMode::ALWAYS),
            ],
            heroMoment:     new \App\Services\AI\FilmOS\Narrative\Production\HeroMoment(1, 'ball overhead'),
            conflicts:      [
                new Conflict('pocket collapsing from both edges', ConflictType::PHYSICAL),
                new Conflict('clock running out', ConflictType::TIME),
            ],
        );
    }
}
