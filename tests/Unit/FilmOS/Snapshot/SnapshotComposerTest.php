<?php

declare(strict_types=1);

namespace Tests\Unit\FilmOS\Snapshot;

use App\Services\AI\FilmOS\Snapshot\ArtifactSection;
use App\Services\AI\FilmOS\Snapshot\DeterminismManifest;
use App\Services\AI\FilmOS\Snapshot\ExecutionSnapshot;
use App\Services\AI\FilmOS\Snapshot\MissingRequiredSnapshotFieldException;
use App\Services\AI\FilmOS\Snapshot\PlanningSection;
use App\Services\AI\FilmOS\Snapshot\SnapshotComposer;
use App\Services\AI\FilmOS\Snapshot\SnapshotSection;
use App\Services\AI\FilmOS\Snapshot\UndeclaredSnapshotFieldException;
use PHPUnit\Framework\TestCase;

final class SnapshotComposerTest extends TestCase
{
    private SnapshotComposer    $composer;
    private DeterminismManifest $manifest;

    protected function setUp(): void
    {
        $this->composer = new SnapshotComposer();
        $this->manifest = DeterminismManifest::current('world-v1');
    }

    // ── Happy path ────────────────────────────────────────────────────────────

    /** @test */
    public function compose_returns_execution_snapshot(): void
    {
        $snapshot = $this->composer->compose(
            'prod-1',
            $this->manifest,
            $this->validPlanningSection(),
        );

        $this->assertInstanceOf(ExecutionSnapshot::class, $snapshot);
        $this->assertSame('prod-1', $snapshot->productionId);
        $this->assertSame('exec_prod-1', $snapshot->executionId);
    }

    /** @test */
    public function compose_with_multiple_sections_merges_fields(): void
    {
        $snapshot = $this->composer->compose(
            'prod-1',
            $this->manifest,
            $this->validPlanningSection(),
            new ArtifactSection('artifact-bundle-hash'),
        );

        $this->assertNotNull($snapshot->get('dagHash'));
        $this->assertNotNull($snapshot->get('artifactBundleHash'));
    }

    /** @test */
    public function compose_canonical_hash_includes_schema_version(): void
    {
        $snapshot = $this->composer->compose('prod-1', $this->manifest, $this->validPlanningSection());
        $hash     = $snapshot->canonicalHash();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    // ── Contract enforcement ──────────────────────────────────────────────────

    /** @test */
    public function missing_required_field_throws(): void
    {
        $this->expectException(MissingRequiredSnapshotFieldException::class);
        $this->expectExceptionMessageMatches("/Section 'bad-section' declares required/");

        $this->composer->compose('prod-1', $this->manifest, $this->sectionMissingRequired());
    }

    /** @test */
    public function undeclared_field_throws(): void
    {
        $this->expectException(UndeclaredSnapshotFieldException::class);
        $this->expectExceptionMessageMatches("/Section 'bad-section' returned undeclared/");

        $this->composer->compose('prod-1', $this->manifest, $this->sectionWithUndeclaredField());
    }

    /** @test */
    public function optional_field_present_is_accepted(): void
    {
        $snapshot = $this->composer->compose('prod-1', $this->manifest, $this->sectionWithOptionalField());

        $this->assertSame('optional-value', $snapshot->get('optionalField'));
    }

    /** @test */
    public function optional_field_absent_is_accepted(): void
    {
        $snapshot = $this->composer->compose('prod-1', $this->manifest, $this->sectionWithoutOptionalField());

        $this->assertNull($snapshot->get('optionalField'));
    }

    // ── Helpers: valid sections ───────────────────────────────────────────────

    private function validPlanningSection(): PlanningSection
    {
        return new PlanningSection(
            dagHash:       'dag-hash',
            goalGraphHash: 'goal-hash',
            promptHash:    'prompt-hash',
            schedulerHash: 'sched-hash',
            policyHash:    'policy-hash',
        );
    }

    // ── Helpers: invalid sections (anonymous classes) ─────────────────────────

    private function sectionMissingRequired(): SnapshotSection
    {
        return new class implements SnapshotSection {
            public static function name(): string         { return 'bad-section'; }
            public static function requiredFields(): array { return ['requiredField']; }
            public static function optionalFields(): array { return []; }
            public function fields(): array               { return []; } // missing requiredField
        };
    }

    private function sectionWithUndeclaredField(): SnapshotSection
    {
        return new class implements SnapshotSection {
            public static function name(): string         { return 'bad-section'; }
            public static function requiredFields(): array { return []; }
            public static function optionalFields(): array { return []; }
            public function fields(): array               { return ['undeclaredField' => 'value']; }
        };
    }

    private function sectionWithOptionalField(): SnapshotSection
    {
        return new class implements SnapshotSection {
            public static function name(): string         { return 'optional-section'; }
            public static function requiredFields(): array { return ['requiredField']; }
            public static function optionalFields(): array { return ['optionalField']; }
            public function fields(): array {
                return ['requiredField' => 'req-value', 'optionalField' => 'optional-value'];
            }
        };
    }

    private function sectionWithoutOptionalField(): SnapshotSection
    {
        return new class implements SnapshotSection {
            public static function name(): string         { return 'optional-section'; }
            public static function requiredFields(): array { return ['requiredField']; }
            public static function optionalFields(): array { return ['optionalField']; }
            public function fields(): array {
                return ['requiredField' => 'req-value']; // optionalField absent — valid
            }
        };
    }
}
