<?php

namespace Tests\Unit\AFOS\Passes;

use App\Services\AI\AFOS\Passes\AfosPassManager;
use App\Services\AI\AFOS\Passes\Pipeline\CompilerMetrics;
use App\Services\AI\AFOS\Passes\Pipeline\PipelineDefinition;
use App\Services\AI\AFOS\Passes\Pipeline\StageCapability;
use App\Services\AI\AFOS\Passes\Pipeline\StageFingerprint;
use PHPUnit\Framework\TestCase;

class StageCapabilityTest extends TestCase
{
    // ── StageCapability enum ──────────────────────────────────────────────────

    public function test_capability_enum_has_correct_values(): void
    {
        $this->assertSame('pure',          StageCapability::PURE->value);
        $this->assertSame('cacheable',     StageCapability::CACHEABLE->value);
        $this->assertSame('cpu_intensive', StageCapability::CPU_INTENSIVE->value);
        $this->assertSame('io_bound',      StageCapability::IO_BOUND->value);
        $this->assertSame('side_effect',   StageCapability::SIDE_EFFECT->value);
    }

    public function test_transform_stages_declare_pure_and_cacheable(): void
    {
        $stages = PipelineDefinition::standard()->stages();

        foreach ($stages as $stage) {
            $meta = $stage->metadata();
            if ($meta->category === 'transform') {
                $this->assertTrue(
                    $meta->hasCapability(StageCapability::PURE),
                    "{$meta->name}: transform stages must be PURE"
                );
                $this->assertTrue(
                    $meta->hasCapability(StageCapability::CACHEABLE),
                    "{$meta->name}: transform stages must be CACHEABLE"
                );
            }
        }
    }

    public function test_validation_stages_declare_pure_only(): void
    {
        $stages = PipelineDefinition::standard()->stages();

        foreach ($stages as $stage) {
            $meta = $stage->metadata();
            if ($meta->category === 'validation') {
                $this->assertTrue(
                    $meta->hasCapability(StageCapability::PURE),
                    "{$meta->name}: validation stages must be PURE"
                );
                $this->assertFalse(
                    $meta->hasCapability(StageCapability::IO_BOUND),
                    "{$meta->name}: validation stages must not be IO_BOUND"
                );
            }
        }
    }

    public function test_describe_includes_capability_strings(): void
    {
        $description = PipelineDefinition::standard()->describe();
        $tier1       = $description[1]; // Tier1Stage

        $this->assertArrayHasKey('capabilities', $tier1);
        $this->assertContains('pure',      $tier1['capabilities']);
        $this->assertContains('cacheable', $tier1['capabilities']);
    }

    public function test_has_capability_returns_false_for_absent_capability(): void
    {
        $meta = PipelineDefinition::standard()->stages()[0]->metadata(); // ShotValidation
        $this->assertFalse($meta->hasCapability(StageCapability::IO_BOUND));
        $this->assertFalse($meta->hasCapability(StageCapability::SIDE_EFFECT));
    }

    // ── StageFingerprint ──────────────────────────────────────────────────────

    public function test_fingerprint_is_deterministic(): void
    {
        $inputs  = $this->inputs();
        $manager = AfosPassManager::defaults();

        // Compile twice, grab fingerprint of Tier1Stage's pre-state — same inputs → same fingerprint
        // We test via public API: compile, extract stage, compute fingerprint from state
        [$shot, $dir, $dp, $intent] = $inputs;

        $state1 = new \App\Services\AI\AFOS\Passes\Pipeline\PipelineState(
            new \App\Services\AI\AFOS\Passes\Pipeline\PipelineInputs($shot, $dir, $dp, $intent),
            new \App\Services\AI\AFOS\Compiler\Diagnostics\DiagnosticBag(),
        );
        $state2 = new \App\Services\AI\AFOS\Passes\Pipeline\PipelineState(
            new \App\Services\AI\AFOS\Passes\Pipeline\PipelineInputs($shot, $dir, $dp, $intent),
            new \App\Services\AI\AFOS\Compiler\Diagnostics\DiagnosticBag(),
        );

        $tier1Stage = PipelineDefinition::standard()->stages()[1]; // Tier1Stage

        $fp1 = StageFingerprint::of($tier1Stage, $state1);
        $fp2 = StageFingerprint::of($tier1Stage, $state2);

        $this->assertTrue($fp1->equals($fp2), 'Same inputs must produce identical fingerprint');
        $this->assertSame($fp1->hash, $fp2->hash);
    }

    public function test_fingerprint_changes_with_different_input(): void
    {
        $tier1 = PipelineDefinition::standard()->stages()[1];
        $bag   = new \App\Services\AI\AFOS\Compiler\Diagnostics\DiagnosticBag();

        [$shot1, $dir, $dp, $intent] = $this->inputs();

        $shot2 = \App\Services\AI\AFOS\Ir\ShotGoalIR::fromArray([
            'shotId'             => 'different-shot',
            'durationSec'        => 10.0,   // different duration
            'goalType'           => 'reveal',
            'goalTarget'         => 'pool',
            'viewerShouldNotice' => ['pool'],
            'viewerShouldIgnore' => [],
            'emotion'            => 'serenity',
            'energy'             => 0.9,    // different energy
            'narrativeFunction'  => 'establish',
        ]);

        $state1 = new \App\Services\AI\AFOS\Passes\Pipeline\PipelineState(
            new \App\Services\AI\AFOS\Passes\Pipeline\PipelineInputs($shot1, $dir, $dp, $intent), $bag,
        );
        $state2 = new \App\Services\AI\AFOS\Passes\Pipeline\PipelineState(
            new \App\Services\AI\AFOS\Passes\Pipeline\PipelineInputs($shot2, $dir, $dp, $intent), $bag,
        );

        $fp1 = StageFingerprint::of($tier1, $state1);
        $fp2 = StageFingerprint::of($tier1, $state2);

        $this->assertFalse($fp1->equals($fp2), 'Different inputs must produce different fingerprints');
    }

    public function test_fingerprint_to_array_has_expected_keys(): void
    {
        [$shot, $dir, $dp, $intent] = $this->inputs();
        $state = new \App\Services\AI\AFOS\Passes\Pipeline\PipelineState(
            new \App\Services\AI\AFOS\Passes\Pipeline\PipelineInputs($shot, $dir, $dp, $intent),
            new \App\Services\AI\AFOS\Compiler\Diagnostics\DiagnosticBag(),
        );
        $tier1 = PipelineDefinition::standard()->stages()[1];
        $fp    = StageFingerprint::of($tier1, $state);
        $arr   = $fp->toArray();

        $this->assertArrayHasKey('stage',   $arr);
        $this->assertArrayHasKey('version', $arr);
        $this->assertArrayHasKey('hash',    $arr);
        $this->assertSame(40, strlen($arr['hash']), 'SHA-1 hash is 40 hex chars');
    }

    // ── CompilerMetrics ───────────────────────────────────────────────────────

    public function test_compiler_metrics_from_profiles(): void
    {
        $snapshot = AfosPassManager::defaults()->compileWithSnapshot(...$this->inputs());
        $metrics  = $snapshot->metrics();

        $this->assertInstanceOf(CompilerMetrics::class, $metrics);
        $this->assertSame(9, $metrics->totalStages);
        $this->assertSame(9, $metrics->executedStages);
        $this->assertGreaterThan(0.0, $metrics->totalMs);
        $this->assertNotNull($metrics->bottleneckStage);
        $this->assertGreaterThan(0.0, $metrics->bottleneckMs);
    }

    public function test_compiler_metrics_total_ms_matches_snapshot(): void
    {
        $snapshot = AfosPassManager::defaults()->compileWithSnapshot(...$this->inputs());
        $metrics  = $snapshot->metrics();

        $this->assertEqualsWithDelta($snapshot->totalCompileMs(), $metrics->totalMs, 0.01);
    }

    public function test_compiler_metrics_bottleneck_is_slowest_stage(): void
    {
        $snapshot = AfosPassManager::defaults()->compileWithSnapshot(...$this->inputs());
        $metrics  = $snapshot->metrics();

        $slowest = array_reduce(
            $snapshot->profiles,
            fn($carry, $p) => ($carry === null || $p->durationMs > $carry->durationMs) ? $p : $carry,
        );

        $this->assertSame($slowest->stageName, $metrics->bottleneckStage);
    }

    public function test_compiler_metrics_to_array_has_required_keys(): void
    {
        $snapshot = AfosPassManager::defaults()->compileWithSnapshot(...$this->inputs());
        $arr      = $snapshot->metrics()->toArray();

        $required = ['total_stages', 'executed_stages', 'cache_hits', 'cache_misses',
                     'total_ms', 'peak_memory_bytes', 'bottleneck_stage', 'bottleneck_ms'];

        foreach ($required as $key) {
            $this->assertArrayHasKey($key, $arr, "Missing key: {$key}");
        }
    }

    public function test_compiler_metrics_not_in_snapshot_to_array(): void
    {
        $snapshot = AfosPassManager::defaults()->compileWithSnapshot(...$this->inputs());
        $arr      = $snapshot->toArray();

        $this->assertArrayNotHasKey('metrics',          $arr);
        $this->assertArrayNotHasKey('compiler_metrics', $arr);
    }

    private function inputs(): array
    {
        return [
            \App\Services\AI\AFOS\Ir\ShotGoalIR::fromArray([
                'shotId'             => 'cap-test',
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
                'name'                => 'cap_dir',
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
                'name'                 => 'cap_dp',
                'lensVocabularyMm'     => [35, 85],
                'lightingStyle'        => 'natural',
                'motionStyle'          => 'SLOW_PUSH',
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
}
