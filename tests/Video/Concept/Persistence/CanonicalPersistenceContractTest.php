<?php

declare(strict_types=1);

namespace Tests\Video\Concept\Persistence;

use App\Models\Article;
use App\Models\VideoProject;
use App\Video\Concept\Canonical\CanonicalDesignSpec;
use App\Video\Concept\Freeze\FrozenCanonicalConcept;
use App\Video\Concept\Freeze\FrozenCanonicalConceptMetadata;
use App\Video\Concept\Persistence\CanonicalConceptInputLoader;
use App\Video\Concept\Persistence\CanonicalFreezePersistenceService;
use App\Video\Concept\Persistence\CanonicalRepairClaimService;
use App\Video\Concept\Persistence\Enums\CanonicalConceptStatus;
use App\Video\Concept\Persistence\Enums\DecisionOrigin;
use App\Video\Concept\Persistence\Ledger\DecisionLedgerWriter;
use App\Video\Concept\Persistence\Models\CanonicalConceptRevision;
use App\Video\Concept\Persistence\Models\CanonicalDecision;
use App\Video\Concept\Persistence\StateMachine\CanonicalConceptStateMachine;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class CanonicalPersistenceContractTest extends TestCase
{
    use DatabaseTransactions;

    public function test_frozen_is_terminal(): void
    {
        $machine = new CanonicalConceptStateMachine;

        foreach (CanonicalConceptStatus::cases() as $target) {
            self::assertFalse(
                $machine->canTransition(
                    CanonicalConceptStatus::FROZEN,
                    $target
                ),
                sprintf(
                    'frozen -> %s phai bi tu choi',
                    $target->value
                ),
            );
        }
    }

    public function test_revision_can_claim_only_one_repair(): void
    {
        $revision = $this->revision();

        $service = app(CanonicalRepairClaimService::class);

        $service->claim($revision->id);

        $this->assertDatabaseHas('canonical_concept_revisions', [
            'id' => $revision->id,
            'repair_count' => 1,
        ]);

        $this->expectException(RuntimeException::class);

        $service->claim($revision->id);
    }

    public function test_frozen_canonical_json_cannot_be_changed(): void
    {
        $revision = $this->frozenRevision();

        $this->expectException(LogicException::class);

        $revision->canonical_json = '{"tampered":true}';
        $revision->save();
    }

    public function test_frozen_revision_cannot_be_deleted(): void
    {
        $revision = $this->frozenRevision();

        $this->expectException(LogicException::class);

        $revision->delete();
    }

    public function test_persisted_hash_matches_exact_canonical_bytes(): void
    {
        $revision = $this->frozenRevision();

        self::assertSame(
            $revision->canonical_hash,
            hash('sha256', (string) $revision->canonical_json),
        );
    }

    public function test_project_revision_number_is_unique(): void
    {
        $revision = $this->revision();

        $this->expectException(QueryException::class);

        CanonicalConceptRevision::query()->create(
            array_merge(
                $this->revisionAttributes(
                    (string) $revision->video_project_id
                ),
                ['revision' => $revision->revision],
            )
        );
    }

    public function test_decision_ledger_is_derived_from_normalized_spec(): void
    {
        $revision = $this->revision();

        app(DecisionLedgerWriter::class)->write(
            $revision,
            $this->spec(),
            DecisionOrigin::GENERATED,
        );

        $this->assertDatabaseHas('canonical_decisions', [
            'canonical_concept_revision_id' => $revision->id,
            'target_path' => 'dimensions.length_m',
            'decision_type' => 'dimension',
        ]);

        $this->assertDatabaseHas('canonical_decisions', [
            'canonical_concept_revision_id' => $revision->id,
            'target_path' => 'relationships.R001',
            'decision_type' => 'relationship',
        ]);
    }

    public function test_decision_ledger_records_value_hash_and_ordinal(): void
    {
        $revision = $this->revision();

        app(DecisionLedgerWriter::class)->write(
            $revision,
            $this->spec(),
            DecisionOrigin::GENERATED,
        );

        $decisions = CanonicalDecision::query()
            ->where('canonical_concept_revision_id', $revision->id)
            ->orderBy('ordinal')
            ->get();

        self::assertGreaterThan(0, $decisions->count());

        self::assertSame(
            range(0, $decisions->count() - 1),
            $decisions->pluck('ordinal')->all(),
            'ordinal phai lien tuc tu 0 — no giu lai thu tu extractor da sap',
        );

        foreach ($decisions as $decision) {
            self::assertMatchesRegularExpression(
                '/^[a-f0-9]{64}$/',
                (string) $decision->value_hash,
            );
        }
    }

    public function test_decision_ledger_is_write_once_per_revision(): void
    {
        $revision = $this->revision();

        $writer = app(DecisionLedgerWriter::class);

        $writer->write(
            $revision,
            $this->spec(),
            DecisionOrigin::GENERATED,
        );

        $this->expectException(LogicException::class);

        $writer->write(
            $revision->fresh(),
            $this->spec(),
            DecisionOrigin::GENERATED,
        );
    }

    public function test_decision_ledger_refuses_to_write_after_freeze(): void
    {
        $revision = $this->frozenRevision();

        $this->expectException(LogicException::class);

        app(DecisionLedgerWriter::class)->write(
            $revision,
            $this->spec(),
            DecisionOrigin::GENERATED,
        );
    }

    public function test_freeze_rolls_back_if_ledger_write_fails(): void
    {
        $revision = $this->revision([
            'status' => CanonicalConceptStatus::FREEZING,
        ]);

        CanonicalDecision::query()->create([
            'canonical_concept_revision_id' => $revision->id,
            'target_path' => 'dimensions.length_m',
            'decision_type' => 'dimension',
            'value' => 120.0,
            'origin' => DecisionOrigin::GENERATED,
            'value_hash' => hash('sha256', '120.0'),
            'ordinal' => 0,
        ]);

        try {
            app(CanonicalFreezePersistenceService::class)->freeze(
                $revision->id,
                $this->frozenConcept(),
                DecisionOrigin::GENERATED,
            );

            self::fail('Freeze phai that bai khi ledger tu choi ghi.');
        } catch (LogicException) {
        }

        $revision = $revision->fresh();

        self::assertNull($revision->canonical_json);
        self::assertNull($revision->canonical_hash);

        self::assertSame(
            CanonicalConceptStatus::FREEZING,
            $revision->status,
        );
    }

    public function test_same_freeze_can_be_replayed_safely(): void
    {
        $revision = $this->revision([
            'status' => CanonicalConceptStatus::FREEZING,
        ]);

        $frozen = $this->frozenConcept();

        $service = app(CanonicalFreezePersistenceService::class);

        $first = $service->freeze(
            $revision->id,
            $frozen,
            DecisionOrigin::GENERATED,
        );

        $second = $service->freeze(
            $revision->id,
            $frozen,
            DecisionOrigin::GENERATED,
        );

        self::assertFalse($first->reused);
        self::assertTrue($second->reused);

        self::assertSame(
            $first->frozen->hash,
            $second->frozen->hash,
        );
    }

    public function test_frozen_revision_rejects_different_hash(): void
    {
        $revision = $this->revision([
            'status' => CanonicalConceptStatus::FREEZING,
        ]);

        $service = app(CanonicalFreezePersistenceService::class);

        $service->freeze(
            $revision->id,
            $this->frozenConcept(),
            DecisionOrigin::GENERATED,
        );

        $this->expectException(RuntimeException::class);

        $service->freeze(
            $revision->id,
            $this->frozenConcept('{"schema_version":"1.0","other":true}'),
            DecisionOrigin::GENERATED,
        );
    }

    public function test_concept_input_snapshot_hash_detects_tampering(): void
    {
        $revision = $this->revision();

        $revision->forceFill([
            'concept_input_json' => '{"tampered":true}',
        ])->saveQuietly();

        $this->expectException(RuntimeException::class);

        app(CanonicalConceptInputLoader::class)->load($revision->fresh());
    }

    private function project(): VideoProject
    {
        $article = Article::create([
            'keyword_id' => DB::table('keywords')->value('id'),
            'category_id' => DB::table('categories')->value('id'),
            'source_url' => 'https://example.com/'.uniqid(),
            'source_url_hash' => md5(uniqid('', true)),
            'source_title' => 'TEST persistence source',
            'title' => 'TEST persistence article '.uniqid(),
            'slug' => 'test-persistence-'.uniqid(),
            'content' => 'noi dung test',
            'status' => 'pending',
        ]);

        return VideoProject::create([
            'title' => 'TEST persistence '.uniqid(),
            'article_id' => $article->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function revisionAttributes(string $projectId): array
    {
        $input = json_encode(
            ['object_type' => 'yacht'],
            JSON_THROW_ON_ERROR
        );

        return [
            'video_project_id' => $projectId,
            'revision' => 1,
            'status' => CanonicalConceptStatus::PENDING,
            'object_type' => 'yacht',
            'profile_key' => 'marine_vessel',
            'profile_version' => '1.0',
            'canonical_schema_version' => '1.0',
            'concept_model' => 'claude-sonnet-5',
            'concept_prompt_version' => 'concept-v1',
            'concept_input_json' => $input,
            'concept_input_hash' => hash('sha256', $input),
            'revision_reason' => 'initial',
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function revision(array $overrides = []): CanonicalConceptRevision
    {
        return CanonicalConceptRevision::query()->create(
            array_merge(
                $this->revisionAttributes($this->project()->id),
                $overrides,
            )
        );
    }

    private function frozenRevision(): CanonicalConceptRevision
    {
        $json = $this->canonicalJson();

        $revision = $this->revision([
            'status' => CanonicalConceptStatus::FREEZING,
        ]);

        $revision->forceFill([
            'status' => CanonicalConceptStatus::FROZEN,
            'canonical_json' => $json,
            'canonical_hash' => hash('sha256', $json),
            'frozen_at' => new DateTimeImmutable('2026-08-30T08:00:00+00:00'),
        ])->saveQuietly();

        return $revision->fresh();
    }

    private function canonicalJson(): string
    {
        return json_encode(
            $this->spec()->toArray(),
            JSON_THROW_ON_ERROR
            | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        );
    }

    private function frozenConcept(?string $canonicalJson = null): FrozenCanonicalConcept
    {
        $json = $canonicalJson ?? $this->canonicalJson();

        return new FrozenCanonicalConcept(
            spec: $this->spec(),
            canonicalJson: $json,
            hash: hash('sha256', $json),
            revision: 1,
            frozenAt: new DateTimeImmutable('2026-08-30T08:00:00+00:00'),
            metadata: new FrozenCanonicalConceptMetadata(
                canonicalSchemaVersion: '1.0',
                profileKey: 'marine_vessel',
                profileVersion: '1.0',
                effectiveSchemaHash: str_repeat('a', 64),
                semanticValidatorVersion: '1.0',
                normalizerVersion: 'canonical-normalizer-v1',
                canonicalizerVersion: 'canonical-json-v1',
                conceptModel: 'claude-sonnet-5',
                conceptPromptVersion: 'concept-v1',
            ),
        );
    }

    private function spec(): CanonicalDesignSpec
    {
        return CanonicalDesignSpec::fromArray([
            'schema_version' => '1.0',
            'object_type' => 'yacht',
            'design_thesis' => [
                'text' => 'One shell tapers aft.',
                'role' => 'soft_design_guidance',
            ],
            'identity' => [
                'subject_class' => 'marine_vessel',
                'identity_basis' => ['opening_layout'],
            ],
            'dimensions' => [
                'length_m' => 120.0,
                'beam_m' => 17.5,
            ],
            'permanent_geometry' => [
                'superstructure' => ['enclosed_deck_levels' => 4],
            ],
            'relationships' => [[
                'id' => 'R001',
                'type' => 'count',
                'subject_path' => 'permanent_geometry.superstructure.enclosed_deck_levels',
                'value' => 4,
            ]],
            'form_relationships' => [
                'governing_line' => 'One sheer runs bow to stern.',
            ],
            'finished_materials' => [
                'hull' => ['material' => 'aluminium'],
            ],
            'exclusions' => [[
                'id' => 'E001',
                'target_path' => 'permanent_geometry',
                'forbid' => 'cantilever',
            ]],
            'invariants' => [[
                'id' => 'I001',
                'name' => 'enclosed_deck_level_count',
                'source_path' => 'permanent_geometry.superstructure.enclosed_deck_levels',
                'constraint_type' => 'count',
                'severity' => 'hard',
                'visual_verification' => true,
            ]],
            'provenance' => [[
                'target_path' => 'dimensions',
                'origin' => 'inspired',
                'source_aspects' => ['size_and_dimensions'],
            ]],
        ]);
    }
}
