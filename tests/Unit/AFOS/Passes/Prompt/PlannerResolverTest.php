<?php

namespace Tests\Unit\AFOS\Passes\Prompt;

use App\Services\AI\AFOS\Creative\CinematographyProfile;
use App\Services\AI\AFOS\Creative\DirectorProfile;
use App\Services\AI\AFOS\Creative\Intent;
use App\Services\AI\AFOS\Compiler\Diagnostics\DiagnosticBag;
use App\Services\AI\AFOS\Ir\PromptIR;
use App\Services\AI\AFOS\Ir\PromptPlanningInput;
use App\Services\AI\AFOS\Ir\ShotGoalIR;
use App\Services\AI\AFOS\Passes\Pipeline\PipelineDefinition;
use App\Services\AI\AFOS\Passes\Pipeline\PipelineInputs;
use App\Services\AI\AFOS\Passes\Pipeline\PipelineState;
use App\Services\AI\AFOS\Passes\Prompt\PlannerRegistry;
use App\Services\AI\AFOS\Passes\Prompt\PlannerResolver;
use App\Services\AI\AFOS\Passes\Prompt\PromptPlannerInterface;
use PHPUnit\Framework\TestCase;

final class PlannerResolverTest extends TestCase
{
    private function makePlanningInput(): PromptPlanningInput
    {
        $shot = ShotGoalIR::fromArray([
            'shotId' => 'planner-test', 'durationSec' => 5.0, 'goalType' => 'reveal',
            'goalTarget' => 'hull', 'viewerShouldNotice' => ['hull'], 'viewerShouldIgnore' => [],
            'emotion' => 'serenity', 'energy' => 0.5, 'narrativeFunction' => 'establish',
        ]);
        $director = DirectorProfile::fromArray([
            'name' => 'test', 'observationWeight' => 0.7, 'motionWeight' => 0.3,
            'revealWeight' => 0.4, 'negativeSpaceWeight' => 0.5, 'symmetryWeight' => 0.3,
            'cutFrequency' => 'slow', 'cameraPhilosophy' => 'slow_observation', 'colorPhilosophy' => 'warm_golden',
        ]);
        $dp = CinematographyProfile::fromArray([
            'name' => 'dp', 'lensVocabularyMm' => [35], 'lightingStyle' => 'natural',
            'motionStyle' => 'SLOW_PUSH', 'depthLayersPreferred' => 3,
        ]);
        $intent = Intent::fromArray([
            'primaryEmotion' => 'serenity', 'secondaryEmotion' => null,
            'narrative' => 'reveal_beauty', 'tempo' => 'meditative',
            'viewerExperience' => 'aspiration', 'desiredTakeaway' => 'Test',
        ]);

        $state  = new PipelineState(new PipelineInputs($shot, $director, $dp, $intent), new DiagnosticBag());
        $stages = PipelineDefinition::standard()->stages();
        // Run through FreezeStage (index 6) to build CameraIR + CompositionIR + frozenGraph
        for ($i = 0; $i <= 6; $i++) {
            $state = $stages[$i]->run($state);
        }

        return new PromptPlanningInput(
            camera:      $state->camera,
            composition: $state->composition,
            intent:      $state->intent,
            temporal:    $state->frozenGraph,
        );
    }

    private function fakePlanner(string $backendId, string $shotId = 'mocked'): PromptPlannerInterface
    {
        return new class($backendId, $shotId) implements PromptPlannerInterface {
            public function __construct(private string $bid, private string $shot) {}
            public function backendId(): string { return $this->bid; }
            public function name(): string { return "MockPlanner[{$this->bid}]"; }
            public function plan(PromptPlanningInput $input): PromptIR {
                return new PromptIR(
                    shotId: $this->shot, subjectClause: 'mock', atmosphereClause: 'mock',
                    cameraClause: 'mock', compositionClause: 'mock',
                    emotionalClose: 'mock', technicalSpec: 'mock',
                );
            }
        };
    }

    // ── withDefaults ──────────────────────────────────────────────────────────

    public function test_with_defaults_resolves_kling(): void
    {
        $resolver = PlannerResolver::withDefaults();
        $input    = $this->makePlanningInput();
        $promptIR = $resolver->plan($input, 'kling');

        $this->assertInstanceOf(PromptIR::class, $promptIR);
        $this->assertNotEmpty($promptIR->subjectClause);
    }

    // ── plan routing ──────────────────────────────────────────────────────────

    public function test_plan_routes_to_correct_planner_by_backend_id(): void
    {
        $veo  = $this->fakePlanner('veo',  'veo-shot');
        $sora = $this->fakePlanner('sora', 'sora-shot');

        $registry = PlannerRegistry::withDefaults()->register($veo)->register($sora);
        $resolver = new PlannerResolver($registry);
        $input    = $this->makePlanningInput();

        $this->assertSame('veo-shot',  $resolver->plan($input, 'veo')->shotId);
        $this->assertSame('sora-shot', $resolver->plan($input, 'sora')->shotId);
    }

    public function test_plan_throws_for_unregistered_backend_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/No prompt planner registered for backend 'veo'/i");

        PlannerResolver::withDefaults()->plan($this->makePlanningInput(), 'veo');
    }

    // ── plannerName ───────────────────────────────────────────────────────────

    public function test_planner_name_returns_name_of_resolved_planner(): void
    {
        $resolver = PlannerResolver::withDefaults();
        $this->assertSame('KlingPromptPlanningPass', $resolver->plannerName('kling'));
    }

    public function test_planner_name_throws_for_unknown_backend(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PlannerResolver::withDefaults()->plannerName('veo');
    }

    // ── determinism ───────────────────────────────────────────────────────────

    public function test_plan_is_deterministic_for_same_input(): void
    {
        $resolver = PlannerResolver::withDefaults();
        $input    = $this->makePlanningInput();

        $r1 = $resolver->plan($input, 'kling');
        $r2 = $resolver->plan($input, 'kling');

        $this->assertSame($r1->subjectClause, $r2->subjectClause);
        $this->assertSame($r1->cameraClause,  $r2->cameraClause);
    }
}
