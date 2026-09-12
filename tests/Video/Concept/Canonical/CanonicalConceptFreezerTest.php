<?php

declare(strict_types=1);

namespace Tests\Video\Concept\Canonical;

use App\Video\Concept\Canonical\CanonicalDesignSpec;
use App\Video\Concept\ConceptInput;
use App\Video\Concept\Freeze\CanonicalConceptFreezer;
use App\Video\Concept\Hashing\CanonicalDesignSpecHasher;
use App\Video\Concept\Hashing\EffectiveConceptSchemaHasher;
use App\Video\Concept\Processing\ProcessedCanonicalConcept;
use App\Video\Concept\Schema\EffectiveConceptSchema;
use App\Video\Concept\Support\Clock;
use App\Video\Concept\Validation\ValidationResult;
use App\Video\Profiles\Validation\CategorySemanticValidator;
use App\Video\Profiles\Validation\CategorySemanticValidatorRegistry;
use DateTimeImmutable;
use InvalidArgumentException;
use Tests\TestCase;

class CanonicalConceptFreezerTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    private function spec(array $overrides = []): CanonicalDesignSpec
    {
        return CanonicalDesignSpec::fromArray(array_replace_recursive([
            'schema_version' => '1.0',
            'object_type' => 'yacht',
            'design_thesis' => ['text' => 'One shell tapers aft.', 'role' => 'soft_design_guidance'],
            'identity' => ['subject_class' => 'marine_vessel', 'identity_basis' => ['opening_layout']],
            'dimensions' => ['length_m' => 120.0, 'beam_m' => 17.5],
            'permanent_geometry' => ['superstructure' => ['enclosed_deck_levels' => 4]],
            'relationships' => [[
                'id' => 'R001', 'type' => 'count',
                'subject_path' => 'permanent_geometry.superstructure.enclosed_deck_levels', 'value' => 4,
            ]],
            'form_relationships' => ['governing_line' => 'One sheer runs bow to stern.'],
            'finished_materials' => ['hull' => ['material' => 'aluminium']],
            'exclusions' => [['id' => 'E001', 'target_path' => 'permanent_geometry', 'forbid' => 'cantilever']],
            'invariants' => [[
                'id' => 'I001', 'name' => 'enclosed_deck_level_count',
                'source_path' => 'permanent_geometry.superstructure.enclosed_deck_levels',
                'constraint_type' => 'count', 'severity' => 'hard', 'visual_verification' => true,
            ]],
            'provenance' => [[
                'target_path' => 'dimensions', 'origin' => 'inspired', 'source_aspects' => ['size_and_dimensions'],
            ]],
        ], $overrides));
    }

    private function effectiveSchema(): EffectiveConceptSchema
    {
        return new EffectiveConceptSchema(
            coreVersion: '1.0',
            profileKey: 'marine_vessel',
            profileVersion: '1.0',
            schema: ['type' => 'object', 'additionalProperties' => true],
        );
    }

    private function processed(string $canonicalJson = '{"schema_version":"1.0"}'): ProcessedCanonicalConcept
    {
        return new ProcessedCanonicalConcept(
            spec: $this->spec(),
            canonicalJson: $canonicalJson,
            effectiveSchema: $this->effectiveSchema(),
        );
    }

    private function freezer(?Clock $clock = null): CanonicalConceptFreezer
    {
        return new CanonicalConceptFreezer(
            new CanonicalDesignSpecHasher,
            new EffectiveConceptSchemaHasher,
            new CategorySemanticValidatorRegistry([
                new class implements CategorySemanticValidator
                {
                    public function profileKey(): string
                    {
                        return 'marine_vessel';
                    }

                    public function version(): string
                    {
                        return 'semantic-test-v1';
                    }

                    public function validate(
                        CanonicalDesignSpec $spec,
                        ConceptInput $input
                    ): ValidationResult {
                        return ValidationResult::valid();
                    }
                },
            ]),
            $clock ?? new class implements Clock
            {
                public function now(): DateTimeImmutable
                {
                    return new DateTimeImmutable('2026-08-30T08:00:00+07:00');
                }
            },
            'claude-sonnet-5',
            'concept-v1',
        );
    }

    public function test_freezer_hashes_exact_validated_canonical_json(): void
    {
        $processed = $this->processed('{"z":1,"a":2}');

        $frozen = $this->freezer()->freeze(
            processed: $processed,
            revision: 3,
        );

        $this->assertSame($processed->canonicalJson, $frozen->canonicalJson);
        $this->assertSame(hash('sha256', $processed->canonicalJson), $frozen->hash);
        $this->assertSame($processed->canonicalJson, $frozen->toArray()['canonical_json']);
    }

    public function test_clock_controls_the_freeze_time(): void
    {
        $time = new DateTimeImmutable('2026-08-30T03:00:00+00:00');

        $frozen = $this->freezer(new class($time) implements Clock
        {
            public function __construct(private readonly DateTimeImmutable $time) {}

            public function now(): DateTimeImmutable
            {
                return $this->time;
            }
        })->freeze(
            processed: $this->processed(),
            revision: 1,
        );

        $this->assertSame($time, $frozen->frozenAt);
        $this->assertSame('2026-08-30T03:00:00+00:00', $frozen->toArray()['frozen_at']);
    }

    public function test_metadata_is_separate_from_the_design_hash(): void
    {
        $frozen = $this->freezer()->freeze(
            processed: $this->processed(),
            revision: 1,
        );

        $this->assertSame(hash('sha256', $frozen->canonicalJson), $frozen->hash);
        $this->assertSame('1.0', $frozen->metadata->canonicalSchemaVersion);
        $this->assertSame('marine_vessel', $frozen->metadata->profileKey);
        $this->assertSame('1.0', $frozen->metadata->profileVersion);
        $this->assertSame('semantic-test-v1', $frozen->metadata->semanticValidatorVersion);
        $this->assertSame('canonical-normalizer-v1', $frozen->metadata->normalizerVersion);
        $this->assertSame('canonical-json-v1', $frozen->metadata->canonicalizerVersion);
        $this->assertSame('claude-sonnet-5', $frozen->metadata->conceptModel);
        $this->assertSame('concept-v1', $frozen->metadata->conceptPromptVersion);
    }

    public function test_the_revision_and_the_timestamp_stay_out_of_the_hash(): void
    {
        $freezer = $this->freezer();
        $processed = $this->processed('{"same":true}');

        $this->assertSame(
            $freezer->freeze($processed, 1)->hash,
            $freezer->freeze($processed, 9)->hash,
        );
    }

    public function test_a_revision_below_one_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('revision must be >= 1');

        $this->freezer()->freeze($this->processed(), 0);
    }
}
