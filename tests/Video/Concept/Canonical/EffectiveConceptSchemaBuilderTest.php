<?php

namespace Tests\Video\Concept\Canonical;

use App\Video\Concept\Claude\CanonicalSchemaProvider;
use App\Video\Concept\Schema\EffectiveConceptSchemaBuilder;
use App\Video\Concept\Schema\JsonSchemaMerger;
use App\Video\Profiles\CategoryCreativeProfile;
use App\Video\Profiles\CategoryProfileSchemaProvider;
use RuntimeException;
use Tests\TestCase;

class EffectiveConceptSchemaBuilderTest extends TestCase
{
    private function builder(): EffectiveConceptSchemaBuilder
    {
        return new EffectiveConceptSchemaBuilder(
            new CanonicalSchemaProvider(
                resource_path('ai/schemas/canonical_design_spec_v1.json')
            ),
            new CategoryProfileSchemaProvider,
            new JsonSchemaMerger,
        );
    }

    private function profile(
        string $key = 'marine_vessel',
        string $version = '1.0',
        ?string $schemaPath = null,
    ): CategoryCreativeProfile {
        return new CategoryCreativeProfile(
            $key,
            $version,
            $schemaPath ?? resource_path('ai/schemas/profiles/marine_vessel_v1.json'),
            ['size_and_dimensions'],
        );
    }

    public function test_it_builds_an_effective_schema_with_stable_metadata(): void
    {
        $schema = $this->builder()->build($this->profile());

        $this->assertSame('canonical@1.0+marine_vessel@1.0', $schema->identifier());
        $this->assertSame('marine_vessel', $schema->metadata()['profile_key']);
        $this->assertSame('1.0', $schema->metadata()['canonical_schema']);
        $this->assertSame(64, strlen($schema->metadata()['effective_schema_hash']));
        $this->assertSame($schema->hash(), $schema->metadata()['effective_schema_hash']);
    }

    public function test_profile_refinements_replace_only_the_domain_open_core_fields(): void
    {
        $schema = $this->builder()->build($this->profile())->schema;

        $this->assertSame(
            ['length_m', 'beam_m'],
            $schema['properties']['dimensions']['required'],
        );
        $this->assertSame(
            'object',
            $schema['properties']['design_thesis']['type'],
        );
    }

    public function test_a_profile_cannot_refine_a_protected_core_field(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'profile_');
        file_put_contents($path, json_encode([
            'profile_key' => 'bad_profile',
            'profile_version' => '1.0',
            'refinements' => [
                'design_thesis' => ['type' => 'object', 'additionalProperties' => false, 'properties' => []],
                'dimensions' => ['type' => 'object', 'additionalProperties' => false, 'properties' => []],
                'permanent_geometry' => ['type' => 'object', 'additionalProperties' => false, 'properties' => []],
                'form_relationships' => ['type' => 'object', 'additionalProperties' => false, 'properties' => []],
                'finished_materials' => ['type' => 'object', 'additionalProperties' => false, 'properties' => []],
            ],
        ], JSON_THROW_ON_ERROR));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('protected canonical field');

        try {
            $this->builder()->build($this->profile('bad_profile', schemaPath: $path));
        } finally {
            @unlink($path);
        }
    }
}
