<?php

declare(strict_types=1);

namespace App\Video\Concept\Schema;

use App\Video\Concept\Claude\CanonicalSchemaProvider;
use App\Video\Profiles\CategoryCreativeProfile;
use App\Video\Profiles\CategoryProfileSchemaProvider;
use RuntimeException;

final class EffectiveConceptSchemaBuilder
{
    /**
     * Profile chi duoc refine nhung field nay
     * trong Canonical Core V1.
     *
     * @var list<string>
     */
    private const REFINABLE_ROOT_FIELDS = [
        'dimensions',
        'permanent_geometry',
        'form_relationships',
        'finished_materials',
    ];

    public function __construct(
        private readonly CanonicalSchemaProvider $coreSchemaProvider,
        private readonly CategoryProfileSchemaProvider $profileSchemaProvider,
        private readonly JsonSchemaMerger $merger = new JsonSchemaMerger,
    ) {}

    public function build(
        CategoryCreativeProfile $profile
    ): EffectiveConceptSchema {
        $core =
            $this->coreSchemaProvider
                ->schema();

        $profileSchema =
            $this->profileSchemaProvider
                ->schema(
                    $profile
                );

        $this->assertProfileMetadata(
            $profile,
            $profileSchema
        );

        $refinements =
            $profileSchema['refinements']
            ?? null;

        if (
            ! is_array($refinements)
            || array_is_list($refinements)
        ) {
            throw new RuntimeException(
                'Profile schema refinements '
                .'must be a JSON object.'
            );
        }

        $core = $this->merger
            ->replaceRootRefinements(
                core: $core,
                refinements: $refinements,
                allowedRootFields: self::REFINABLE_ROOT_FIELDS,
            );

        return new EffectiveConceptSchema(
            coreVersion: $this->coreVersion($core),

            profileKey: $profile->key,

            profileVersion: $profile->version,

            schema: $core,
        );
    }

    /**
     * @param  array<string,mixed>  $profileSchema
     */
    private function assertProfileMetadata(
        CategoryCreativeProfile $profile,
        array $profileSchema
    ): void {
        $key =
            $profileSchema['profile_key']
            ?? null;

        $version =
            $profileSchema['profile_version']
            ?? null;

        if (
            $key !== $profile->key
        ) {
            throw new RuntimeException(
                sprintf(
                    'Profile schema key mismatch. '
                    .'Registry=%s Schema=%s',
                    $profile->key,
                    (string) $key
                )
            );
        }

        if (
            $version !== $profile->version
        ) {
            throw new RuntimeException(
                sprintf(
                    'Profile schema version mismatch. '
                    .'Registry=%s Schema=%s',
                    $profile->version,
                    (string) $version
                )
            );
        }
    }

    /**
     * @param  array<string,mixed>  $core
     */
    private function coreVersion(
        array $core
    ): string {
        $schemaVersion =
            $core['properties']['schema_version']['const']
            ?? null;

        if (! is_string($schemaVersion)) {
            throw new RuntimeException(
                'Unable to determine '
                .'canonical core version.'
            );
        }

        return $schemaVersion;
    }
}
