<?php

namespace Tests\Unit\AFOS\Passes;

use App\Services\AI\AFOS\Compiler\Diagnostics\DiagnosticBag;
use App\Services\AI\AFOS\Passes\AfosPassManager;
use App\Services\AI\AFOS\Passes\Cache\InMemoryCompilerCache;
use App\Services\AI\AFOS\Passes\Pipeline\CanonicalSerializer;
use App\Services\AI\AFOS\Passes\Pipeline\PipelineDefinition;
use App\Services\AI\AFOS\Passes\Pipeline\PipelineInputs;
use App\Services\AI\AFOS\Passes\Pipeline\PipelineState;
use App\Services\AI\AFOS\Passes\Pipeline\StageFingerprint;
use PHPUnit\Framework\TestCase;

class CacheManagerTest extends TestCase
{
    // ── InMemoryCompilerCache raw API ─────────────────────────────────────────

    public function test_cache_miss_returns_null(): void
    {
        $cache = new InMemoryCompilerCache();
        $state = $this->makeState();
        $stage = PipelineDefinition::standard()->stages()[1]; // Tier1Stage

        $fp = StageFingerprint::of($stage, $state);
        $this->assertNull($cache->get($fp, $state));
    }

    public function test_cache_hit_returns_stored_state(): void
    {
        $cache = new InMemoryCompilerCache();
        $state = $this->makeState();
        $stage = PipelineDefinition::standard()->stages()[1];
        $fp    = StageFingerprint::of($stage, $state);

        $cache->put($fp, $state);
        $restored = $cache->get($fp, $state);

        $this->assertNotNull($restored);
        $this->assertInstanceOf(PipelineState::class, $restored);
    }

    public function test_cache_returns_different_keys_as_miss(): void
    {
        $cache  = new InMemoryCompilerCache();
        $state  = $this->makeState();
        $stages = PipelineDefinition::standard()->stages();

        $fp1 = StageFingerprint::of($stages[1], $state); // Tier1Stage
        $fp2 = StageFingerprint::of($stages[2], $state); // Tier2Stage

        $cache->put($fp1, $state);

        $this->assertNotNull($cache->get($fp1, $state));
        $this->assertNull($cache->get($fp2, $state));
    }

    public function test_cache_transplants_live_bag(): void
    {
        $cache = new InMemoryCompilerCache();
        $stage = PipelineDefinition::standard()->stages()[1];

        // Run once to populate cache with a real post-Tier1 state
        $manager = AfosPassManager::defaults()->withCache($cache);
        $manager->compileWithSnapshot(...$this->inputs());

        // Now create a fresh bag (simulating a new compile run)
        $liveBag  = new DiagnosticBag();
        $liveBag->hint('from_live_run');

        $newState = $this->makeState($liveBag);
        $fp       = StageFingerprint::of($stage, $newState);
        $restored = $cache->get($fp, $newState);

        $this->assertNotNull($restored, 'Tier1Stage should be in cache after first compile');
        $this->assertSame($liveBag, $restored->bag, 'Restored state must carry the live bag, not the cached one');
        $this->assertCount(1, $restored->bag->hints(), 'Live bag hint must survive through cache transplant');
    }

    public function test_cache_stats_track_hits_and_misses(): void
    {
        $cache = new InMemoryCompilerCache();
        $state = $this->makeState();
        $stage = PipelineDefinition::standard()->stages()[1];
        $fp    = StageFingerprint::of($stage, $state);

        $cache->get($fp, $state);  // miss
        $cache->put($fp, $state);
        $cache->get($fp, $state);  // hit
        $cache->get($fp, $state);  // hit

        $stats = $cache->stats();
        $this->assertSame(1, $stats->misses);
        $this->assertSame(2, $stats->hits);
        $this->assertSame(1, $stats->size);
        $this->assertEqualsWithDelta(2 / 3, $stats->hitRate, 0.01);
    }

    public function test_cache_stats_hit_rate_zero_when_no_gets(): void
    {
        $cache = new InMemoryCompilerCache();
        $this->assertSame(0.0, $cache->stats()->hitRate);
    }

    public function test_cache_flush_resets_all_state(): void
    {
        $cache = new InMemoryCompilerCache();
        $state = $this->makeState();
        $stage = PipelineDefinition::standard()->stages()[1];
        $fp    = StageFingerprint::of($stage, $state);

        $cache->put($fp, $state);
        $cache->get($fp, $state);  // hit — stats non-zero

        $cache->flush();

        $stats = $cache->stats();
        $this->assertSame(0, $stats->hits);
        $this->assertSame(0, $stats->misses);
        $this->assertSame(0, $stats->size);
        $this->assertNull($cache->get($fp, $state));  // miss after flush
    }

    // ── Integration: AfosPassManager::withCache() ─────────────────────────────

    public function test_compile_with_cache_produces_same_output(): void
    {
        $cache   = new InMemoryCompilerCache();
        $manager = AfosPassManager::defaults()->withCache($cache);
        $inputs  = $this->inputs();

        $snap1 = $manager->compileWithSnapshot(...$inputs);
        $snap2 = $manager->compileWithSnapshot(...$inputs);

        $this->assertSame($snap1->artifacts->compiledPrompt, $snap2->artifacts->compiledPrompt);
        $this->assertSame($snap1->semanticHash, $snap2->semanticHash);
    }

    public function test_second_compile_has_cache_hits(): void
    {
        $cache   = new InMemoryCompilerCache();
        $manager = AfosPassManager::defaults()->withCache($cache);
        $inputs  = $this->inputs();

        $manager->compileWithSnapshot(...$inputs);  // cold — populates cache
        $manager->compileWithSnapshot(...$inputs);  // warm — hits cache

        $stats = $cache->stats();
        $this->assertGreaterThan(0, $stats->hits, 'Second compile should produce cache hits');
        $this->assertGreaterThan(0.0, $stats->hitRate);
    }

    public function test_with_cache_is_immutable(): void
    {
        $original = AfosPassManager::defaults();
        $cached   = $original->withCache(new InMemoryCompilerCache());

        $this->assertNotSame($original, $cached);

        // Original operates without cache — no side effects on the clone
        $snap = $original->compileWithSnapshot(...$this->inputs());
        $this->assertNotEmpty($snap->artifacts->compiledPrompt);
    }

    public function test_cache_skips_only_cacheable_stages(): void
    {
        // Tier1, MotionBeat, Tier2, CameraArc, TemporalAssembly, Tier3, Backend = 7 CACHEABLE stages
        // ShotValidation, CameraValidation = NOT CACHEABLE (always run)
        $cache   = new InMemoryCompilerCache();
        $manager = AfosPassManager::defaults()->withCache($cache);
        $inputs  = $this->inputs();

        $manager->compileWithSnapshot(...$inputs);  // cold run
        $manager->compileWithSnapshot(...$inputs);  // warm run

        $stats = $cache->stats();
        $this->assertSame(7, $stats->hits, '7 CACHEABLE stages should hit on second compile');
        $this->assertSame(7, $stats->misses, '7 CACHEABLE stages missed on first compile');
    }

    public function test_warm_compile_profiles_show_near_zero_duration_for_cache_hits(): void
    {
        $cache   = new InMemoryCompilerCache();
        $manager = AfosPassManager::defaults()->withCache($cache);
        $inputs  = $this->inputs();

        $manager->compileWithSnapshot(...$inputs);   // cold
        $snap = $manager->compileWithSnapshot(...$inputs);  // warm

        $cacheableNames = ['Tier1Stage', 'Tier2Stage', 'Tier3Stage', 'BackendStage'];

        foreach ($snap->profiles as $profile) {
            if (in_array($profile->stageName, $cacheableNames, true)) {
                $this->assertLessThan(5.0, $profile->durationMs,
                    "Cache-hit stage '{$profile->stageName}' should finish in near-zero ms"
                );
            }
        }
    }

    // ── CanonicalSerializer stability ─────────────────────────────────────────

    public function test_canonical_serializer_sorts_keys(): void
    {
        $a = CanonicalSerializer::encode(['z' => 1, 'a' => 2]);
        $b = CanonicalSerializer::encode(['a' => 2, 'z' => 1]);
        $this->assertSame($a, $b, 'Key order must not affect canonical encoding');
    }

    public function test_canonical_serializer_sorts_nested_keys(): void
    {
        $a = CanonicalSerializer::encode(['outer' => ['z' => 1, 'a' => 2]]);
        $b = CanonicalSerializer::encode(['outer' => ['a' => 2, 'z' => 1]]);
        $this->assertSame($a, $b);
    }

    public function test_canonical_serializer_preserves_list_order(): void
    {
        $a = CanonicalSerializer::encode([1, 2, 3]);
        $b = CanonicalSerializer::encode([3, 2, 1]);
        $this->assertNotSame($a, $b, 'List order must be preserved');
    }

    public function test_canonical_serializer_hash_is_sha1(): void
    {
        $h = CanonicalSerializer::hash(['key' => 'value']);
        $this->assertSame(40, strlen($h), 'SHA-1 is 40 hex chars');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $h);
    }

    public function test_canonical_serializer_same_value_same_hash(): void
    {
        $payload = ['name' => 'Tier1Stage', 'version' => '1.0', 'reads' => ['ShotGoalIR']];
        $this->assertSame(
            CanonicalSerializer::hash($payload),
            CanonicalSerializer::hash($payload)
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeState(?DiagnosticBag $bag = null): PipelineState
    {
        [$shot, $dir, $dp, $intent] = $this->inputs();
        return new PipelineState(new PipelineInputs($shot, $dir, $dp, $intent), $bag ?? new DiagnosticBag());
    }

    private function inputs(): array
    {
        return [
            \App\Services\AI\AFOS\Ir\ShotGoalIR::fromArray([
                'shotId'             => 'cache-test',
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
                'name'                => 'cache_dir',
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
                'name'                 => 'cache_dp',
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
