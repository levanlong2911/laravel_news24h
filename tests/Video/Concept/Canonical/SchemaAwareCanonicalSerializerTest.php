<?php

declare(strict_types=1);

namespace Tests\Video\Concept\Canonical;

use App\Video\Concept\Canonical\CanonicalDesignSpec;
use App\Video\Concept\Claude\CanonicalSchemaProvider;
use App\Video\Concept\Hashing\CanonicalDesignSpecHasher;
use App\Video\Concept\Normalize\CanonicalDesignSpecNormalizer;
use App\Video\Concept\Schema\EffectiveConceptSchema;
use App\Video\Concept\Schema\EffectiveConceptSchemaBuilder;
use App\Video\Concept\Serialization\CanonicalSerializationException;
use App\Video\Concept\Serialization\JsonSchemaTypeResolver;
use App\Video\Concept\Serialization\SchemaAwareCanonicalSerializer;
use App\Video\Profiles\CategoryCreativeProfile;
use App\Video\Profiles\CategoryProfileSchemaProvider;
use stdClass;
use Tests\TestCase;

class SchemaAwareCanonicalSerializerTest extends TestCase
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

    private function serializer(): SchemaAwareCanonicalSerializer
    {
        return new SchemaAwareCanonicalSerializer(
            new JsonSchemaTypeResolver
        );
    }

    private function normalizer(): CanonicalDesignSpecNormalizer
    {
        return new CanonicalDesignSpecNormalizer;
    }

    private function hasher(): CanonicalDesignSpecHasher
    {
        return new CanonicalDesignSpecHasher;
    }

    private function effectiveSchema(): EffectiveConceptSchema
    {
        return (new EffectiveConceptSchemaBuilder(
            $this->schemaProvider(),
            new CategoryProfileSchemaProvider,
        ))->build(
            new CategoryCreativeProfile(
                'marine_vessel',
                '1.0',
                resource_path('ai/schemas/profiles/marine_vessel_v1.json'),
                ['size_and_dimensions', 'materials'],
            )
        );
    }

    /** @param array<string,mixed> $overrides */
    private function spec(array $overrides = []): CanonicalDesignSpec
    {
        $base = [
            'schema_version' => '1.0',
            'object_type' => 'yacht',
            'design_thesis' => ['text' => 'One shell tapers aft.', 'role' => 'soft_design_guidance'],
            'identity' => ['subject_class' => 'marine_vessel', 'identity_basis' => ['opening_layout'], 'finish_identity_basis' => []],
            'dimensions' => ['length_m' => 120, 'beam_m' => 20, 'length_to_beam_ratio' => 6],
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
        ];

        foreach (['relationships', 'exclusions', 'invariants', 'provenance'] as $listKey) {
            if (array_key_exists($listKey, $overrides)) {
                $base[$listKey] = $overrides[$listKey];

                unset($overrides[$listKey]);
            }
        }

        return CanonicalDesignSpec::fromArray(array_replace_recursive($base, $overrides));
    }

    /** @return array<string,mixed> */
    private function decode(string $json): array
    {
        return json_decode(
            $json,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    private function decodeObject(string $json): stdClass
    {
        return json_decode(
            $json,
            false,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    public function test_empty_object_is_serialized_as_json_object(): void
    {
        $json = $this->serializer()->serialize(
            $this->spec(['finished_materials' => []]),
            $this->effectiveSchema(),
        );

        $this->assertInstanceOf(
            stdClass::class,
            $this->decodeObject($json)->finished_materials
        );
    }

    public function test_empty_relationships_remain_json_array(): void
    {
        $json = $this->serializer()->serialize(
            $this->spec(['relationships' => []]),
            $this->effectiveSchema(),
        );

        $decoded = $this->decode($json);

        $this->assertSame([], $decoded['relationships']);
    }

    public function test_nested_empty_object_preserves_object_type(): void
    {
        $json = $this->serializer()->serialize(
            $this->spec([
                'permanent_geometry' => [
                    'openings' => [],
                ],
            ]),
            $this->effectiveSchema(),
        );

        $this->assertInstanceOf(
            stdClass::class,
            $this->decodeObject($json)
                ->permanent_geometry
                ->openings
        );
    }

    public function test_same_semantics_produce_same_hash(): void
    {
        $effective = $this->effectiveSchema();

        $jsonA = $this->serializer()->serialize(
            $this->normalizer()->normalize($this->spec()),
            $effective,
        );

        $jsonB = $this->serializer()->serialize(
            $this->normalizer()->normalize($this->spec([
                'relationships' => [
                    ['id' => 'R001', 'type' => 'count',
                        'subject_path' => 'permanent_geometry.superstructure.primary_tier_count', 'value' => 4],
                    ['id' => 'R002', 'type' => 'count',
                        'subject_path' => 'permanent_geometry.superstructure.primary_tier_count', 'value' => 4],
                ],
            ])),
            $effective,
        );

        $this->assertSame($jsonA, $jsonB);
        $this->assertSame(
            $this->hasher()->hash($jsonA),
            $this->hasher()->hash($jsonB),
        );
    }

    public function test_number_schema_normalizes_int_and_float_equivalently(): void
    {
        $effective = $this->effectiveSchema();

        $jsonA = $this->serializer()->serialize(
            $this->spec(['dimensions' => ['length_to_beam_ratio' => 6]]),
            $effective,
        );

        $jsonB = $this->serializer()->serialize(
            $this->spec(['dimensions' => ['length_to_beam_ratio' => 6.0]]),
            $effective,
        );

        $this->assertSame($jsonA, $jsonB);
        $this->assertSame(6.0, $this->decode($jsonA)['dimensions']['length_to_beam_ratio']);
    }

    public function test_integer_schema_does_not_become_float(): void
    {
        $json = $this->serializer()->serialize(
            $this->spec(),
            $this->effectiveSchema(),
        );

        $this->assertSame(4, $this->decode($json)['relationships'][0]['value']);
    }

    public function test_serializer_rejects_unknown_property(): void
    {
        $this->expectException(CanonicalSerializationException::class);
        $this->expectExceptionMessage('Unknown property $.dimensions.banana.');

        $this->serializer()->serialize(
            $this->spec(['dimensions' => ['banana' => 'x']]),
            $this->effectiveSchema(),
        );
    }

    public function test_relationship_one_of_uses_type_discriminator(): void
    {
        $json = $this->serializer()->serialize(
            $this->spec([
                'relationships' => [[
                    'id' => 'R001',
                    'type' => 'count',
                    'subject_path' => 'permanent_geometry.superstructure.primary_tier_count',
                    'value' => 4,
                ]],
            ]),
            $this->effectiveSchema(),
        );

        $decoded = $this->decode($json);

        $this->assertSame('count', $decoded['relationships'][0]['type']);
        $this->assertSame(4, $decoded['relationships'][0]['value']);
    }
}
