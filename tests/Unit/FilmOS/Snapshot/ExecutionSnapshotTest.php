<?php

declare(strict_types=1);

namespace Tests\Unit\FilmOS\Snapshot;

use App\Services\AI\FilmOS\Snapshot\DeterminismManifest;
use App\Services\AI\FilmOS\Snapshot\DuplicateSnapshotFieldException;
use App\Services\AI\FilmOS\Snapshot\ExecutionSnapshot;
use App\Services\AI\FilmOS\Snapshot\PlanningSection;
use App\Services\AI\FilmOS\Snapshot\ProviderSection;
use PHPUnit\Framework\TestCase;

final class ExecutionSnapshotTest extends TestCase
{
    private DeterminismManifest $manifest;

    protected function setUp(): void
    {
        $this->manifest = DeterminismManifest::current('world-v1');
    }

    // ── canonicalHash ─────────────────────────────────────────────────────────

    /** @test */
    public function canonical_hash_is_a_64_char_hex_string(): void
    {
        $snapshot = $this->makeSnapshot();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $snapshot->canonicalHash());
    }

    /** @test */
    public function canonical_hash_is_stable_across_multiple_calls(): void
    {
        $snapshot = $this->makeSnapshot();
        $this->assertSame($snapshot->canonicalHash(), $snapshot->canonicalHash());
    }

    /** @test */
    public function two_snapshots_with_same_sections_produce_same_hash(): void
    {
        $a = $this->makeSnapshot();
        $b = $this->makeSnapshot();

        $this->assertSame($a->canonicalHash(), $b->canonicalHash());
    }

    /** @test */
    public function different_dag_hash_produces_different_canonical_hash(): void
    {
        $a = $this->makeSnapshot(dagHash: 'hash-aaa');
        $b = $this->makeSnapshot(dagHash: 'hash-bbb');

        $this->assertNotSame($a->canonicalHash(), $b->canonicalHash());
    }

    /** @test */
    public function different_manifest_world_version_produces_different_canonical_hash(): void
    {
        $a = new ExecutionSnapshot(
            manifest:     DeterminismManifest::current('world-v1'),
            executionId:  'exec_1',
            productionId: 'prod_1',
            capturedAt:   0.0,
            sections:     [$this->planningSection()],
        );
        $b = new ExecutionSnapshot(
            manifest:     DeterminismManifest::current('world-v2'),
            executionId:  'exec_1',
            productionId: 'prod_1',
            capturedAt:   0.0,
            sections:     [$this->planningSection()],
        );

        $this->assertNotSame($a->canonicalHash(), $b->canonicalHash());
    }

    /** @test */
    public function canonical_hash_excludes_execution_id_and_captured_at(): void
    {
        $a = new ExecutionSnapshot(
            manifest:     $this->manifest,
            executionId:  'exec_RUN_A',
            productionId: 'prod_same',
            capturedAt:   1000.0,
            sections:     [$this->planningSection()],
        );
        $b = new ExecutionSnapshot(
            manifest:     $this->manifest,
            executionId:  'exec_RUN_B',
            productionId: 'prod_same',
            capturedAt:   9999.0,
            sections:     [$this->planningSection()],
        );

        // Different executionId and capturedAt → same hash (excluded from canonical)
        $this->assertSame($a->canonicalHash(), $b->canonicalHash());
    }

    // ── shortHash ─────────────────────────────────────────────────────────────

    /** @test */
    public function short_hash_is_12_chars(): void
    {
        $this->assertSame(12, strlen($this->makeSnapshot()->shortHash()));
    }

    // ── gaps ──────────────────────────────────────────────────────────────────

    /** @test */
    public function gaps_returns_fields_with_null_value(): void
    {
        $section = new PlanningSection(
            dagHash:       'hash-dag',
            goalGraphHash: 'hash-goal',
            promptHash:    'hash-prompt',
            schedulerHash: null,    // gap
            policyHash:    null,    // gap
        );
        $snapshot = new ExecutionSnapshot(
            manifest:     $this->manifest,
            executionId:  'x',
            productionId: 'x',
            capturedAt:   0.0,
            sections:     [$section],
        );

        $this->assertSame(['policyHash', 'schedulerHash'], $snapshot->gaps());
    }

    /** @test */
    public function gaps_returns_empty_when_all_fields_are_populated(): void
    {
        $this->assertSame([], $this->makeSnapshot()->gaps());
    }

    // ── allFields ─────────────────────────────────────────────────────────────

    /** @test */
    public function all_fields_returns_sorted_field_names(): void
    {
        $snapshot = $this->makeSnapshot();
        $fields   = $snapshot->allFields();

        $sorted = $fields;
        sort($sorted);
        $this->assertSame($sorted, $fields);
    }

    // ── get ───────────────────────────────────────────────────────────────────

    /** @test */
    public function get_returns_correct_value_for_known_field(): void
    {
        $snapshot = $this->makeSnapshot(dagHash: 'expected-dag-hash');
        $this->assertSame('expected-dag-hash', $snapshot->get('dagHash'));
    }

    /** @test */
    public function get_returns_null_for_unknown_field(): void
    {
        $this->assertNull($this->makeSnapshot()->get('nonExistentField'));
    }

    // ── diffWith ──────────────────────────────────────────────────────────────

    /** @test */
    public function diff_with_identical_snapshots_returns_empty_array(): void
    {
        $a = $this->makeSnapshot();
        $b = $this->makeSnapshot();

        $this->assertSame([], $a->diffWith($b));
    }

    /** @test */
    public function diff_with_reports_changed_fields(): void
    {
        $a = $this->makeSnapshot(dagHash: 'hash-original');
        $b = $this->makeSnapshot(dagHash: 'hash-replay');

        $diff = $a->diffWith($b);

        $this->assertArrayHasKey('dagHash', $diff);
        $this->assertSame('hash-original', $diff['dagHash']['original']);
        $this->assertSame('hash-replay',   $diff['dagHash']['replay']);
    }

    /** @test */
    public function diff_with_does_not_include_matching_fields(): void
    {
        $a = $this->makeSnapshot(dagHash: 'differs');
        $b = $this->makeSnapshot(dagHash: 'also-differs');

        $diff = $a->diffWith($b);

        $this->assertArrayHasKey('dagHash', $diff);
        $this->assertArrayNotHasKey('goalGraphHash', $diff);
        $this->assertArrayNotHasKey('promptHash', $diff);
    }

    // ── Duplicate field detection ─────────────────────────────────────────────

    /** @test */
    public function duplicate_field_key_across_sections_throws(): void
    {
        $this->expectException(DuplicateSnapshotFieldException::class);

        // Both PlanningSection and ProviderSection declare 'capabilityHash' intentionally doesn't overlap,
        // but we can fake it by making a section that declares the same key as PlanningSection.
        // Easiest: use two PlanningSection instances (dagHash appears in both).
        $snapshot = new ExecutionSnapshot(
            manifest:     $this->manifest,
            executionId:  'x',
            productionId: 'x',
            capturedAt:   0.0,
            sections:     [$this->planningSection(), $this->planningSection()],
        );

        $snapshot->canonicalHash(); // triggers fieldIndex()
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeSnapshot(string $dagHash = 'hash-dag'): ExecutionSnapshot
    {
        return new ExecutionSnapshot(
            manifest:     $this->manifest,
            executionId:  'exec_test',
            productionId: 'prod_test',
            capturedAt:   microtime(true),
            sections:     [$this->planningSection(dagHash: $dagHash)],
        );
    }

    private function planningSection(string $dagHash = 'hash-dag'): PlanningSection
    {
        return new PlanningSection(
            dagHash:       $dagHash,
            goalGraphHash: 'hash-goal',
            promptHash:    'hash-prompt',
            schedulerHash: 'hash-sched',
            policyHash:    'hash-policy',
        );
    }
}
