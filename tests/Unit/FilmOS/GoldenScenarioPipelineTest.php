<?php

declare(strict_types=1);

namespace Tests\Unit\FilmOS;

use App\Services\AI\FilmOS\Testing\GoldenScenarioPipeline;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the core property of the golden scenario pipeline:
 * identical inputs → identical ExecutionSnapshot canonical hash.
 *
 * These tests are the definition of what "determinism" means for FilmOS.
 * A failure here means a non-determinism bug was introduced in the pipeline.
 */
final class GoldenScenarioPipelineTest extends TestCase
{
    private GoldenScenarioPipeline $pipeline;

    protected function setUp(): void
    {
        $this->pipeline = new GoldenScenarioPipeline();
    }

    // ── Core determinism invariant ────────────────────────────────────────────

    /** @test */
    public function two_runs_produce_identical_canonical_hash(): void
    {
        $a = $this->pipeline->run('run_a');
        $b = $this->pipeline->run('run_b');

        $this->assertSame(
            $a->canonicalHash(),
            $b->canonicalHash(),
            "Golden scenario pipeline is non-deterministic — hash diverged between run_a and run_b.\n" .
            "Diverged fields: " . implode(', ', array_keys($a->diffWith($b)))
        );
    }

    /** @test */
    public function three_runs_all_produce_identical_canonical_hash(): void
    {
        $snapshots = [
            $this->pipeline->run('run_1'),
            $this->pipeline->run('run_2'),
            $this->pipeline->run('run_3'),
        ];

        $refHash = $snapshots[0]->canonicalHash();
        foreach ($snapshots as $i => $snapshot) {
            $this->assertSame($refHash, $snapshot->canonicalHash(),
                "Run #{$i} diverged from Run #1");
        }
    }

    // ── runId exclusion from canonical hash ──────────────────────────────────

    /** @test */
    public function different_run_ids_produce_same_canonical_hash(): void
    {
        $a = $this->pipeline->run('prod_20260710_001');
        $b = $this->pipeline->run('verify_run_42');

        $this->assertSame($a->canonicalHash(), $b->canonicalHash(),
            "runId should not affect the canonical hash (executionId is excluded)");
    }

    // ── Snapshot structure ────────────────────────────────────────────────────

    /** @test */
    public function snapshot_includes_planning_section_fields(): void
    {
        $snapshot = $this->pipeline->run('struct_test');

        $this->assertNotNull($snapshot->get('dagHash'));
        $this->assertNotNull($snapshot->get('goalGraphHash'));
        $this->assertNotNull($snapshot->get('promptHash'));
    }

    /** @test */
    public function snapshot_includes_artifact_section_fields(): void
    {
        $snapshot = $this->pipeline->run('artifact_test');

        $this->assertNotNull($snapshot->get('artifactBundleHash'),
            "Phase F ArtifactSection should be included in the golden scenario snapshot");
    }

    /** @test */
    public function snapshot_has_no_gaps(): void
    {
        $snapshot = $this->pipeline->run('gap_test');
        $gaps     = $snapshot->gaps();

        $this->assertEmpty($gaps,
            "Golden scenario snapshot should have no null fields (gaps: " . implode(', ', $gaps) . ")");
    }

    // ── Facts contract ────────────────────────────────────────────────────────

    /** @test */
    public function facts_returns_exactly_four_entries(): void
    {
        $this->assertCount(4, GoldenScenarioPipeline::facts());
    }

    /** @test */
    public function facts_each_have_required_keys(): void
    {
        $required = ['id', 'text', 'category', 'visual_relevance', 'confidence', 'visual_hint'];
        foreach (GoldenScenarioPipeline::facts() as $fact) {
            foreach ($required as $key) {
                $this->assertArrayHasKey($key, $fact, "Fact is missing key: {$key}");
            }
        }
    }

    /** @test */
    public function facts_ids_are_unique(): void
    {
        $ids = array_column(GoldenScenarioPipeline::facts(), 'id');
        $this->assertSame(count($ids), count(array_unique($ids)));
    }
}
