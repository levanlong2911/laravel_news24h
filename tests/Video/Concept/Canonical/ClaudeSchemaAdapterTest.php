<?php

namespace Tests\Video\Concept\Canonical;

use App\Video\Concept\CanonicalJsonPayloadFactory;
use App\Video\Concept\Claude\ClaudeSchemaAdapter;
use App\Video\Concept\Schema\EffectiveConceptSchemaBuilder;
use App\Video\Profiles\CategoryCreativeProfileResolver;
use ReflectionMethod;
use Tests\TestCase;

class ClaudeSchemaAdapterTest extends TestCase
{
    /** @return array<string, mixed> */
    private function adaptedSchema(string $objectType = 'yacht'): array
    {
        $profile = app(CategoryCreativeProfileResolver::class)->resolve($objectType);

        return app(ClaudeSchemaAdapter::class)->adapt(
            app(EffectiveConceptSchemaBuilder::class)->build($profile)->schema,
        );
    }

    public function test_provider_schema_is_a_small_canonical_json_envelope(): void
    {
        $schema = $this->adaptedSchema();

        $this->assertSame('object', $schema['type']);
        $this->assertFalse($schema['additionalProperties']);
        $this->assertSame(['canonical_json'], $schema['required']);
        $this->assertSame(
            ['type' => 'string'],
            $schema['properties']['canonical_json'],
        );
        $this->assertArrayNotHasKey('relationships', $schema['properties']);
    }

    public function test_a_compact_provider_relationship_is_expanded_before_validation(): void
    {
        $payload = (new CanonicalJsonPayloadFactory)->create(json_encode([
            'schema_version' => '1.0',
            'object_type' => 'yacht',
            'design_thesis' => ['text' => 'x', 'role' => 'soft_design_guidance'],
            'identity' => ['subject_class' => 'marine_vessel', 'identity_basis' => ['x'], 'finish_identity_basis' => []],
            'dimensions' => new \stdClass,
            'permanent_geometry' => new \stdClass,
            'relationships' => [[
                'id' => 'R001',
                'type' => 'count',
                'subject_path' => 'permanent_geometry.superstructure.primary_tier_count',
                'source_path' => '',
                'target_path' => '',
                'reference_path' => '',
                'container_path' => '',
                'contained_path' => '',
                'metric' => '',
                'value' => '',
                'number_value' => 0,
                'integer_value' => 4,
                'group_count' => 1,
                'items' => ['a', 'b'],
                'members' => ['a', 'b'],
                'axis' => '',
                'direction' => '',
                'start_path' => '',
                'end_path' => '',
                'grouped_into_path' => '',
                'meaning' => '',
            ]],
            'form_relationships' => new \stdClass,
            'finished_materials' => new \stdClass,
            'exclusions' => [],
            'invariants' => [[
                'id' => 'I001',
                'name' => 'x',
                'source_path' => 'relationships.0.value',
                'constraint_type' => 'count',
                'severity' => 'hard',
                'visual_verification' => true,
            ]],
            'provenance' => [],
        ], JSON_THROW_ON_ERROR));

        $this->assertSame([
            'id' => 'R001',
            'type' => 'count',
            'subject_path' => 'permanent_geometry.superstructure.primary_tier_count',
            'value' => 4,
        ], $payload->data['relationships'][0]);
    }

    public function test_provider_envelope_is_unwrapped_before_validation(): void
    {
        $canonical = json_encode([
            'schema_version' => '1.0',
            'object_type' => 'yacht',
            'design_thesis' => ['text' => 'x', 'role' => 'soft_design_guidance'],
            'identity' => ['subject_class' => 'marine_vessel', 'identity_basis' => ['x'], 'finish_identity_basis' => []],
            'dimensions' => new \stdClass,
            'permanent_geometry' => new \stdClass,
            'relationships' => [],
            'form_relationships' => new \stdClass,
            'finished_materials' => new \stdClass,
            'exclusions' => [],
            'invariants' => [[
                'id' => 'I001',
                'name' => 'x',
                'source_path' => 'identity.subject_class',
                'constraint_type' => 'state',
                'severity' => 'hard',
                'visual_verification' => true,
            ]],
            'provenance' => [],
        ], JSON_THROW_ON_ERROR);

        $payload = (new CanonicalJsonPayloadFactory)->create(json_encode([
            'canonical_json' => $canonical,
        ], JSON_THROW_ON_ERROR));

        $this->assertSame('1.0', $payload->data['schema_version']);
        $this->assertSame('yacht', $payload->data['object_type']);
    }

    /**
     * Doi lai viec bat buoc hoa moi property: model se tra `null` cho o no
     * khong dung. Core schema khai `additionalProperties: false` va khong co
     * truong nullable nao, nen phai bo truoc khi bat cu validator nao nhin thay.
     */
    public function test_a_null_the_provider_filled_in_is_dropped_before_validation(): void
    {
        $cleaned = $this->stripped(json_encode([
            'id' => 'R001',
            'type' => 'count',
            'subject_path' => 'permanent_geometry.superstructure.enclosed_deck_levels',
            'value' => 4,
            'meaning' => null,
            'tolerance' => null,
            'nested' => ['keep' => 1, 'drop' => null],
        ], JSON_THROW_ON_ERROR));

        $this->assertSame([
            'id' => 'R001',
            'type' => 'count',
            'subject_path' => 'permanent_geometry.superstructure.enclosed_deck_levels',
            'value' => 4,
            'nested' => ['keep' => 1],
        ], json_decode($cleaned, true, 512, JSON_THROW_ON_ERROR));
    }

    /**
     * RANH GIOI CO Y: mot object RONG khac mot mang RONG. Decode assoc roi
     * encode lai se bien `{}` thanh `[]`, va core schema se tu choi no.
     */
    public function test_an_empty_object_does_not_become_an_empty_array(): void
    {
        $this->assertStringContainsString(
            '"finished_materials":{}',
            $this->stripped('{"finished_materials":{},"exclusions":[]}'),
        );
    }

    /**
     * `null` NAM TRONG mang khong phai o trong ma la phan tu sai kieu — de
     * validator bao ra, dung giau di.
     */
    public function test_a_null_inside_a_list_is_left_for_the_validator_to_reject(): void
    {
        $this->assertSame(
            '{"members":["a",null,"b"]}',
            $this->stripped('{"members":["a",null,"b"]}'),
        );
    }

    public function test_json_that_does_not_parse_is_handed_back_untouched(): void
    {
        $this->assertSame('{ khong phai json', $this->stripped('{ khong phai json'));
    }

    private function stripped(string $rawJson): string
    {
        $method = new ReflectionMethod(CanonicalJsonPayloadFactory::class, 'withoutProviderNulls');
        $method->setAccessible(true);

        return $method->invoke(new CanonicalJsonPayloadFactory, $rawJson);
    }
}
