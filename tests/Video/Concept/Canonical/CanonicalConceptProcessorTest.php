<?php

namespace Tests\Video\Concept\Canonical;

use App\Video\Concept\CanonicalConceptProcessor;
use App\Video\Concept\Claude\CanonicalSchemaProvider;
use App\Video\Concept\ConceptInput;
use App\Video\Concept\Exceptions\CanonicalValidationException;
use App\Video\Concept\Normalize\CanonicalDesignSpecNormalizer;
use App\Video\Concept\Schema\EffectiveConceptSchemaBuilder;
use App\Video\Concept\Serialization\JsonSchemaTypeResolver;
use App\Video\Concept\Serialization\SchemaAwareCanonicalSerializer;
use App\Video\Concept\Validation\CanonicalCrossFieldValidator;
use App\Video\Concept\Validation\CanonicalDesignSpecValidator;
use App\Video\Concept\Validation\CanonicalPathValidator;
use App\Video\Concept\Validation\CanonicalProvenanceValidator;
use App\Video\Concept\Validation\CanonicalSchemaValidator;
use App\Video\Concept\Validation\EffectiveSchemaValidator;
use App\Video\Inspiration\ExcludedContext;
use App\Video\Inspiration\InspirationBrief;
use App\Video\Inspiration\SourceInsight;
use App\Video\Profiles\CategoryCreativeProfile;
use App\Video\Profiles\CategoryCreativeProfileRegistry;
use App\Video\Profiles\CategoryCreativeProfileResolver;
use App\Video\Profiles\CategoryProfileSchemaProvider;
use App\Video\Profiles\Validation\CategorySemanticValidatorRegistry;
use App\Video\Profiles\Validation\MarineVesselSemanticValidator;
use App\Video\Profiles\Validation\ProfileCompatibilityValidator;
use Tests\TestCase;

class CanonicalConceptProcessorTest extends TestCase
{
    /**
     * Lay dung duong ma production lay, khong hard-code lai.
     *
     * Hard-code `resources/...` chi la doi mot ban sao lay mot ban sao khac: khi
     * `config('canonical_concept.schema.path')` doi, test se lai doc mot file khac
     * voi production ma khong ai biet.
     */
    private function schemaProvider(): CanonicalSchemaProvider
    {
        return new CanonicalSchemaProvider(
            (string) config('canonical_concept.schema.path'),
        );
    }

    private function processor(): CanonicalConceptProcessor
    {
        return new CanonicalConceptProcessor(
            new CanonicalSchemaValidator($this->schemaProvider()),
            new EffectiveSchemaValidator,
            new EffectiveConceptSchemaBuilder(
                $this->schemaProvider(),
                new CategoryProfileSchemaProvider,
            ),
            new CanonicalDesignSpecValidator(
                new CanonicalCrossFieldValidator(new CanonicalPathValidator),
                new CanonicalProvenanceValidator(new CanonicalPathValidator),
                new ProfileCompatibilityValidator(
                    new CategoryCreativeProfileResolver(
                        new CategoryCreativeProfileRegistry([$this->profile()]),
                        ['yacht' => 'marine_vessel'],
                        'marine_vessel',
                    )
                ),
                new CategorySemanticValidatorRegistry([
                    new MarineVesselSemanticValidator,
                ]),
            ),
            new CanonicalDesignSpecNormalizer,
            new SchemaAwareCanonicalSerializer(new JsonSchemaTypeResolver),
        );
    }

    private function profile(): CategoryCreativeProfile
    {
        return new CategoryCreativeProfile(
            'marine_vessel',
            '1.0',
            resource_path('ai/schemas/profiles/marine_vessel_v1.json'),
            ['size_and_dimensions', 'materials'],
        );
    }

    private function brief(): InspirationBrief
    {
        return new InspirationBrief(
            ['design_profile'],
            'A design profile.',
            [new SourceInsight('size_and_dimensions', 'It is long.', ['120 metres'])],
            [new ExcludedContext('owner', 'Jane Doe')],
        );
    }

    private function input(): ConceptInput
    {
        return new ConceptInput(
            'yacht',
            $this->brief(),
            $this->profile(),
        );
    }

    /** @param array<string, mixed> $overrides */
    private function specJson(array $overrides = []): string
    {
        return json_encode(array_replace_recursive([
            'schema_version' => '1.0',
            'object_type' => 'yacht',
            'design_thesis' => ['text' => 'One shell tapers aft.', 'role' => 'soft_design_guidance'],
            'identity' => ['subject_class' => 'marine_vessel', 'identity_basis' => ['opening_layout'], 'finish_identity_basis' => []],
            'dimensions' => ['length_m' => 120.0, 'beam_m' => 17.5],
            'permanent_geometry' => [
                'hull' => ['type' => 'displacement', 'sheer' => 'continuous'],
                'bow' => ['stem' => 'near_plumb', 'waterline_entry' => 'fine'],
                'stern' => ['type' => 'plumb_transom'],
                'superstructure' => ['primary_tier_count' => 4, 'massing' => 'central_aft'],
            ],
            'relationships' => [
                ['id' => 'R002', 'type' => 'count',
                    'subject_path' => 'permanent_geometry.superstructure.primary_tier_count', 'value' => 4],
                ['id' => 'R001', 'type' => 'count',
                    'subject_path' => 'permanent_geometry.superstructure.primary_tier_count', 'value' => 4],
            ],
            'form_relationships' => ['hull_to_superstructure' => 'One sheer runs bow to stern.'],
            'finished_materials' => ['hull' => 'aluminium'],
            'exclusions' => [['id' => 'E001', 'target_path' => 'permanent_geometry', 'forbid' => 'cantilever']],
            'invariants' => [[
                'id' => 'I001', 'name' => 'enclosed_deck_level_count',
                'source_path' => 'permanent_geometry.superstructure.primary_tier_count',
                'constraint_type' => 'count', 'severity' => 'hard', 'visual_verification' => true,
            ]],
            'provenance' => [[
                'target_path' => 'dimensions', 'origin' => 'inspired', 'source_aspects' => ['size_and_dimensions'],
            ]],
        ], $overrides), JSON_THROW_ON_ERROR);
    }

    public function test_it_returns_a_normalised_spec_when_every_gate_passes(): void
    {
        $spec = $this->processor()->process($this->specJson(), $this->input());

        $this->assertSame('yacht', $spec->spec->objectType);
        $this->assertSame(['R001', 'R002'], array_column($spec->spec->toArray()['relationships'], 'id'));
        $this->assertNotSame('', $spec->canonicalJson);
    }

    public function test_the_schema_gate_runs_before_hydration(): void
    {
        $this->expectException(CanonicalValidationException::class);
        $this->expectExceptionMessage('Canonical JSON Schema validation failed.');

        $this->processor()->process($this->specJson(['schema_version' => '2.0']), $this->input());
    }

    public function test_prose_instead_of_json_is_refused_by_the_first_gate(): void
    {
        $this->expectException(CanonicalValidationException::class);

        $this->processor()->process('not json at all', $this->input());
    }

    public function test_the_semantic_gate_runs_after_the_schema_gate(): void
    {
        try {
            $this->processor()->process(
                $this->specJson(['relationships' => [['value' => 9]]]),
                $this->input(),
            );
            $this->fail('Expected the count mismatch to be rejected.');
        } catch (CanonicalValidationException $rejected) {
            $this->assertSame('Canonical semantic validation failed.', $rejected->getMessage());
            $this->assertContains(
                'count_relationship_mismatch',
                array_column($rejected->errorPayload(), 'code'),
            );
        }
    }

    /**
     * Bon truong domain-open rong phai giu {} qua vong tai-serialize. json_encode
     * tran bien [] thanh [], va schema doi object — lan validate thu hai se do
     * neu serializer khong ep kieu.
     */
    public function test_an_empty_domain_open_object_survives_the_second_schema_gate(): void
    {
        $spec = $this->processor()->process(
            $this->specJson(['finished_materials' => new \stdClass]),
            $this->input(),
        );

        $this->assertSame([], $spec->spec->finishedMaterials);
    }
}
