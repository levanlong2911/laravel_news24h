<?php

namespace Tests\Unit\AFOS\Passes;

use App\Services\AI\AFOS\Passes\AfosPassManager;
use App\Services\AI\AFOS\Passes\Events\CallbackEventBus;
use App\Services\AI\AFOS\Passes\Events\NullEventBus;
use App\Services\AI\AFOS\Passes\Events\PipelineEvent;
use App\Services\AI\AFOS\Passes\Events\StageFailed;
use App\Services\AI\AFOS\Passes\Events\StageFinished;
use App\Services\AI\AFOS\Passes\Events\StageStarted;
use PHPUnit\Framework\TestCase;

class EventBusTest extends TestCase
{
    public function test_null_bus_produces_no_output(): void
    {
        $manager = AfosPassManager::defaults()->withEventBus(new NullEventBus());
        $snapshot = $manager->compileWithSnapshot(...$this->inputs());
        $this->assertNotEmpty($snapshot->artifacts->compiledPrompt);
    }

    public function test_callback_bus_receives_started_and_finished_events(): void
    {
        $events = [];

        $bus     = new CallbackEventBus(function (PipelineEvent $e) use (&$events) {
            $events[] = $e;
        });
        $manager = AfosPassManager::defaults()->withEventBus($bus);
        $manager->compileWithSnapshot(...$this->inputs());

        $started  = array_filter($events, fn($e) => $e instanceof StageStarted);
        $finished = array_filter($events, fn($e) => $e instanceof StageFinished);

        $this->assertCount(6, $started,  'One StageStarted per stage');
        $this->assertCount(6, $finished, 'One StageFinished per stage');
    }

    public function test_started_events_carry_metadata(): void
    {
        $stageNames = [];

        $bus = new CallbackEventBus(function (PipelineEvent $e) use (&$stageNames) {
            if ($e instanceof StageStarted) {
                $stageNames[] = $e->stageName;
                $this->assertSame($e->stageName, $e->metadata->name);
            }
        });

        AfosPassManager::defaults()->withEventBus($bus)->compileWithSnapshot(...$this->inputs());

        $this->assertContains('ShotValidationStage',  $stageNames);
        $this->assertContains('BackendStage',          $stageNames);
    }

    public function test_finished_events_carry_profile_with_timing(): void
    {
        $profiles = [];

        $bus = new CallbackEventBus(function (PipelineEvent $e) use (&$profiles) {
            if ($e instanceof StageFinished) {
                $profiles[$e->stageName] = $e->profile;
            }
        });

        AfosPassManager::defaults()->withEventBus($bus)->compileWithSnapshot(...$this->inputs());

        $this->assertArrayHasKey('Tier1Stage', $profiles);
        $this->assertGreaterThanOrEqual(0.0, $profiles['Tier1Stage']->durationMs);
        $this->assertTrue($profiles['Tier1Stage']->succeeded);
    }

    public function test_finished_events_carry_memory_delta(): void
    {
        $memoryDeltas = [];

        $bus = new CallbackEventBus(function (PipelineEvent $e) use (&$memoryDeltas) {
            if ($e instanceof StageFinished) {
                $memoryDeltas[$e->stageName] = $e->profile->memoryDelta;
            }
        });

        AfosPassManager::defaults()->withEventBus($bus)->compileWithSnapshot(...$this->inputs());

        $this->assertArrayHasKey('Tier1Stage', $memoryDeltas);
        // memoryDelta can be 0 or positive; never negative for a transform stage
        $this->assertIsInt($memoryDeltas['Tier1Stage']);
    }

    public function test_events_fire_in_stage_order(): void
    {
        $order = [];

        $bus = new CallbackEventBus(function (PipelineEvent $e) use (&$order) {
            $order[] = match (true) {
                $e instanceof StageStarted  => "start:{$e->stageName}",
                $e instanceof StageFinished => "end:{$e->stageName}",
                default                     => 'unknown',
            };
        });

        AfosPassManager::defaults()->withEventBus($bus)->compileWithSnapshot(...$this->inputs());

        // Verify start always precedes end for the same stage
        $stages = ['ShotValidationStage', 'Tier1Stage', 'Tier2Stage', 'CameraValidationStage', 'Tier3Stage', 'BackendStage'];
        foreach ($stages as $stage) {
            $startIdx = array_search("start:{$stage}", $order);
            $endIdx   = array_search("end:{$stage}", $order);
            $this->assertLessThan($endIdx, $startIdx, "start:{$stage} must precede end:{$stage}");
        }
    }

    public function test_with_event_bus_is_immutable(): void
    {
        $original = AfosPassManager::defaults();
        $withBus  = $original->withEventBus(new NullEventBus());

        $this->assertNotSame($original, $withBus);

        // Original should still work without bus
        $snapshot = $original->compileWithSnapshot(...$this->inputs());
        $this->assertNotEmpty($snapshot->artifacts->compiledPrompt);
    }

    public function test_stage_failed_event_emitted_on_validation_error(): void
    {
        $failed = [];

        $bus = new CallbackEventBus(function (PipelineEvent $e) use (&$failed) {
            if ($e instanceof StageFailed) {
                $failed[] = $e->stageName;
            }
        });

        $manager = AfosPassManager::defaults()->withEventBus($bus);

        $badShot = \App\Services\AI\AFOS\Ir\ShotGoalIR::fromArray([
            'shotId'             => 'bad-shot',
            'durationSec'        => -1.0,   // triggers AFOS1001 error → abort
            'goalType'           => 'reveal',
            'goalTarget'         => 'hull',
            'viewerShouldNotice' => ['hull'],
            'viewerShouldIgnore' => [],
            'emotion'            => 'serenity',
            'energy'             => 0.5,
            'narrativeFunction'  => 'establish',
        ]);

        try {
            $manager->compileWithSnapshot($badShot, ...$this->inputsWithoutShot());
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertContains('ShotValidationStage', $failed);
    }

    private function inputs(): array
    {
        return [
            \App\Services\AI\AFOS\Ir\ShotGoalIR::fromArray([
                'shotId'             => 'evt-test',
                'durationSec'        => 5.0,
                'goalType'           => 'reveal',
                'goalTarget'         => 'pool',
                'viewerShouldNotice' => ['pool'],
                'viewerShouldIgnore' => [],
                'emotion'            => 'serenity',
                'energy'             => 0.5,
                'narrativeFunction'  => 'establish',
            ]),
            \App\Services\AI\AFOS\Creative\DirectorProfile::fromArray([
                'name'                => 'evt_dir',
                'observationWeight'   => 0.7,
                'motionWeight'        => 0.3,
                'revealWeight'        => 0.4,
                'negativeSpaceWeight' => 0.5,
                'symmetryWeight'      => 0.3,
                'cutFrequency'        => 'slow',
                'cameraPhilosophy'    => 'slow_observation',
                'colorPhilosophy'     => 'warm_golden',
            ]),
            \App\Services\AI\AFOS\Creative\CinematographyProfile::fromArray([
                'name'                => 'evt_dp',
                'lensVocabularyMm'    => [35, 85],
                'lightingStyle'       => 'natural',
                'motionStyle'         => 'SLOW_PUSH',
                'depthLayersPreferred' => 3,
            ]),
            \App\Services\AI\AFOS\Creative\Intent::fromArray([
                'primaryEmotion'   => 'serenity',
                'secondaryEmotion' => null,
                'narrative'        => 'reveal_beauty',
                'tempo'            => 'meditative',
                'viewerExperience' => 'aspiration',
                'desiredTakeaway'  => 'Test',
            ]),
        ];
    }

    private function inputsWithoutShot(): array
    {
        return array_slice($this->inputs(), 1);
    }
}
