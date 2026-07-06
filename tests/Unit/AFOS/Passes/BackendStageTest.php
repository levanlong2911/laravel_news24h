<?php

namespace Tests\Unit\AFOS\Passes;

use App\Services\AI\AFOS\Backends\BackendEmitter;
use App\Services\AI\AFOS\Backends\BackendInterface;
use App\Services\AI\AFOS\Backends\BackendRegistry;
use App\Services\AI\AFOS\Compiler\Diagnostics\DiagnosticBag;
use App\Services\AI\AFOS\Creative\CinematographyProfile;
use App\Services\AI\AFOS\Creative\DirectorProfile;
use App\Services\AI\AFOS\Creative\Intent;
use App\Services\AI\AFOS\Ir\BackendInput;
use App\Services\AI\AFOS\Ir\CameraIR;
use App\Services\AI\AFOS\Ir\CompositionIR;
use App\Services\AI\AFOS\Ir\PromptIR;
use App\Services\AI\AFOS\Ir\ShotGoalIR;
use App\Services\AI\AFOS\Passes\Pipeline\CompilerPhase;
use App\Services\AI\AFOS\Passes\Pipeline\PipelineInputs;
use App\Services\AI\AFOS\Passes\Pipeline\PipelineState;
use App\Services\AI\AFOS\Passes\Stages\BackendStage;
use PHPUnit\Framework\TestCase;

final class BackendStageTest extends TestCase
{
    private function fakeEmitter(string $output): BackendEmitter
    {
        $fake = new class($output) implements BackendInterface {
            public function __construct(private string $out) {}
            public function id(): string { return 'kling'; }
            public function serialize(PromptIR $p): string { return $this->out; }
        };

        return new BackendEmitter(BackendRegistry::withDefaults()->register($fake));
    }

    private function makeState(CompilerPhase $phase = CompilerPhase::LOWER): PipelineState
    {
        $shot = ShotGoalIR::fromArray([
            'shotId'             => 'backend-test',
            'durationSec'        => 5.0,
            'goalType'           => 'reveal',
            'goalTarget'         => 'hull',
            'viewerShouldNotice' => ['hull'],
            'viewerShouldIgnore' => [],
            'emotion'            => 'serenity',
            'energy'             => 0.5,
            'narrativeFunction'  => 'establish',
        ]);
        $director = DirectorProfile::fromArray([
            'name'                => 'test',
            'observationWeight'   => 0.7,
            'motionWeight'        => 0.3,
            'revealWeight'        => 0.4,
            'negativeSpaceWeight' => 0.5,
            'symmetryWeight'      => 0.3,
            'cutFrequency'        => 'slow',
            'cameraPhilosophy'    => 'slow_observation',
            'colorPhilosophy'     => 'warm_golden',
        ]);
        $dp = CinematographyProfile::fromArray([
            'name'                => 'test_dp',
            'lensVocabularyMm'    => [35],
            'lightingStyle'       => 'natural',
            'motionStyle'         => 'SLOW_PUSH',
            'depthLayersPreferred' => 3,
        ]);
        $intent = Intent::fromArray([
            'primaryEmotion'   => 'serenity',
            'secondaryEmotion' => null,
            'narrative'        => 'reveal_beauty',
            'tempo'            => 'meditative',
            'viewerExperience' => 'aspiration',
            'desiredTakeaway'  => 'Test',
        ]);

        $promptIR = new PromptIR(
            shotId:            'backend-test',
            subjectClause:     'Hull emerges',
            atmosphereClause:  'Golden hour',
            cameraClause:      'Push',
            compositionClause: 'Thirds',
            emotionalClose:    'Grandeur',
            technicalSpec:     '4K',
        );

        $state = new PipelineState(new PipelineInputs($shot, $director, $dp, $intent), new DiagnosticBag());

        // Advance to the required phase via stage-like manipulation
        if ($phase === CompilerPhase::LOWER) {
            // Simulate: BUILD → FREEZE → LOWER (via withPhase shortcuts for test isolation)
            $state = new PipelineState(
                new PipelineInputs($shot, $director, $dp, $intent),
                new DiagnosticBag(),
                new \App\Services\AI\AFOS\Passes\Pipeline\IRState(promptIR: $promptIR),
                CompilerPhase::LOWER,
            );
        }

        return $state;
    }

    // ── run() ─────────────────────────────────────────────────────────────────

    public function test_run_writes_compiled_prompt(): void
    {
        $stage = new BackendStage($this->fakeEmitter('fake-output'));
        $out   = $stage->run($this->makeState());

        $this->assertSame('fake-output', $out->compiledPrompt);
    }

    public function test_run_delegates_to_emitter_not_directly_to_backend(): void
    {
        $emitterCalled = false;
        $fake = new class($emitterCalled) implements BackendInterface {
            public function __construct(public bool &$called) {}
            public function id(): string { return 'kling'; }
            public function serialize(PromptIR $p): string { $this->called = true; return 'done'; }
        };

        $emitter = new BackendEmitter(BackendRegistry::withDefaults()->register($fake));
        $stage   = new BackendStage($emitter);
        $stage->run($this->makeState());

        $this->assertTrue($emitterCalled, 'BackendStage must delegate to emitter');
    }

    public function test_run_throws_if_phase_is_not_lower(): void
    {
        $state = new PipelineState(
            new PipelineInputs(
                ShotGoalIR::fromArray([
                    'shotId' => 'x', 'durationSec' => 5.0, 'goalType' => 'reveal',
                    'goalTarget' => 'hull', 'viewerShouldNotice' => [], 'viewerShouldIgnore' => [],
                    'emotion' => 'serenity', 'energy' => 0.5, 'narrativeFunction' => 'establish',
                ]),
                DirectorProfile::fromArray([
                    'name' => 'x', 'observationWeight' => 0.5, 'motionWeight' => 0.5,
                    'revealWeight' => 0.5, 'negativeSpaceWeight' => 0.5, 'symmetryWeight' => 0.5,
                    'cutFrequency' => 'slow', 'cameraPhilosophy' => 'slow_observation', 'colorPhilosophy' => 'warm_golden',
                ]),
                CinematographyProfile::fromArray([
                    'name' => 'x', 'lensVocabularyMm' => [35], 'lightingStyle' => 'natural',
                    'motionStyle' => 'SLOW_PUSH', 'depthLayersPreferred' => 3,
                ]),
                Intent::fromArray([
                    'primaryEmotion' => 'serenity', 'secondaryEmotion' => null,
                    'narrative' => 'reveal_beauty', 'tempo' => 'meditative',
                    'viewerExperience' => 'aspiration', 'desiredTakeaway' => 'Test',
                ]),
            ),
            new DiagnosticBag(),
        ); // BUILD phase

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/expected lower.*build/i');

        (new BackendStage())->run($state);
    }

    public function test_run_throws_if_prompt_ir_is_null(): void
    {
        $state = $this->makeState();
        // State has phase=LOWER but no promptIR — simulate by constructing without it
        $stateNoPrompt = new PipelineState(
            new \App\Services\AI\AFOS\Passes\Pipeline\PipelineInputs(
                $state->shot, $state->director, $state->dp, $state->intent,
            ),
            new DiagnosticBag(),
            new \App\Services\AI\AFOS\Passes\Pipeline\IRState(),
            CompilerPhase::LOWER,
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/BackendStage requires PromptIR/i');

        (new BackendStage($this->fakeEmitter('x')))->run($stateNoPrompt);
    }

    // ── default emitter ───────────────────────────────────────────────────────

    public function test_default_constructor_uses_kling_backend(): void
    {
        $stage  = new BackendStage(); // no emitter injected → defaults to Kling
        $output = $stage->run($this->makeState());

        $this->assertNotEmpty($output->compiledPrompt);
    }
}
