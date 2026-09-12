<?php

declare(strict_types=1);

namespace App\Video\Profiles;

use JsonException;
use RuntimeException;

final class CategoryProfileSchemaProvider
{
    /**
     * @var array<string,array<string,mixed>>
     */
    private array $cache = [];

    /**
     * @return array<string,mixed>
     */
    public function schema(
        CategoryCreativeProfile $profile
    ): array {
        $cacheKey =
            $profile->identifier();

        if (
            isset(
                $this->cache[
                    $cacheKey
                ]
            )
        ) {
            return $this->cache[
                $cacheKey
            ];
        }

        if (
            ! is_file(
                $profile->schemaPath
            )
        ) {
            throw new RuntimeException(
                'Profile schema not found: '
                .$profile->schemaPath
            );
        }

        $json =
            file_get_contents(
                $profile->schemaPath
            );

        if ($json === false) {
            throw new RuntimeException(
                'Unable to read profile schema: '
                .$profile->schemaPath
            );
        }

        try {
            $schema =
                json_decode(
                    $json,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
        } catch (JsonException $e) {
            throw new RuntimeException(
                'Invalid profile schema JSON: '
                .$profile->schemaPath
                .' - '
                .$e->getMessage(),
                previous: $e,
            );
        }

        if (
            ! is_array($schema)
            || array_is_list($schema)
        ) {
            throw new RuntimeException(
                'Profile schema root '
                .'must be a JSON object.'
            );
        }

        return $this->cache[
            $cacheKey
        ] = $schema;
    }
}
