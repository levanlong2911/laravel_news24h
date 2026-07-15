<?php

declare(strict_types=1);

namespace Tests\Unit\FilmOS\Prompting;

use App\Services\AI\FilmOS\Narrative\Character\CharacterEmotion;
use App\Services\AI\FilmOS\Narrative\Character\EmotionalState;
use App\Services\AI\FilmOS\Narrative\Character\EmotionIntensity;
use App\Services\AI\FilmOS\Narrative\Performance\CharacterPerformance;
use App\Services\AI\FilmOS\Narrative\Performance\PerformanceCue;
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
use App\Services\AI\FilmOS\Prompting\IR\StructuredPrompt;
use App\Services\AI\FilmOS\Prompting\IR\SubjectDescriptor;
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

        $this->assertStringContainsString('close-up', $out->positive);
        $this->assertStringContainsString('85mm telephoto compression', $out->positive);
    }

    public function test_emotion_is_phrased_with_intensity(): void
    {
        $out = $this->render($this->fullPrompt());

        $this->assertStringContainsString('intensely fearful', $out->positive);
    }

    public function test_performance_cues_appear_in_order(): void
    {
        $out = $this->render($this->fullPrompt());

        $holds = strpos($out->positive, 'holds breath');
        $jaw   = strpos($out->positive, 'jaw tightens');
        $this->assertNotFalse($holds);
        $this->assertNotFalse($jaw);
        $this->assertLessThan($jaw, $holds, 'cue order (temporal) is preserved');
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
        $this->assertStringContainsString('keep the football visible after release', $out->positive);
        $this->assertStringNotContainsString('football visible after release', (string) $out->negative);
    }

    public function test_motifs_and_hero_moment_appear(): void
    {
        $out = $this->render($this->fullPrompt());

        $this->assertStringContainsString('spiral', $out->positive);
        $this->assertStringContainsString('hero frame', $out->positive);
    }

    public function test_metadata_carries_duration_and_energy_peak(): void
    {
        $out = $this->render($this->fullPrompt());

        $this->assertSame('kling', $out->metadata['provider']);
        $this->assertSame(4.5, $out->metadata['duration_seconds']);   // 2.0 + 2.5
        $this->assertSame(100, $out->metadata['energy_peak']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function render(StructuredPrompt $p): \App\Services\AI\FilmOS\Prompting\Adapter\RenderedPrompt
    {
        return (new KlingPromptRenderer())->render($p);
    }

    private function subject(WorldObjectType $type, string $label): SubjectDescriptor
    {
        return new SubjectDescriptor('obj', $type, $label, AttributeBag::empty(), isPrimary: true);
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
            directorIntent: new \App\Services\AI\FilmOS\Narrative\Production\DirectorIntent('all is lost'),
            motifs:         [new VisualMotif('spiral', MotifImportance::PRIMARY)],
            constraints:    [
                new VisualConstraint('crowd', 'blocking the hero', ConstraintMode::NEVER),
                new VisualConstraint('football', 'visible after release', ConstraintMode::ALWAYS),
            ],
            heroMoment:     new \App\Services\AI\FilmOS\Narrative\Production\HeroMoment(1, 'ball overhead'),
        );
    }
}
