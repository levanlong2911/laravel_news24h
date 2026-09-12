<?php

declare(strict_types=1);

namespace App\Video\Concept\Hashing;

use App\Video\Concept\Schema\EffectiveConceptSchema;
use JsonException;
use RuntimeException;
use stdClass;

final class EffectiveConceptSchemaHasher
{
    public const VERSION = 'effective-schema-hash-v1';

    public function hash(
        EffectiveConceptSchema $schema
    ): string {
        $canonical =
            $this->canonicalize(
                $schema->schema
            );

        try {
            $json =
                json_encode(
                    $canonical,
                    JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                    | JSON_PRESERVE_ZERO_FRACTION
                );
        } catch (JsonException $e) {
            throw new RuntimeException(
                'Unable to encode effective schema.',
                previous: $e,
            );
        }

        return hash(
            'sha256',
            $json
        );
    }

    private function canonicalize(
        mixed $value
    ): mixed {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(
                fn (mixed $item): mixed => $this->canonicalize(
                    $item
                ),
                $value
            );
        }

        ksort(
            $value,
            SORT_STRING
        );

        $object =
            new stdClass;

        foreach (
            $value as $key => $child
        ) {
            $object->{$key} =
                $this->canonicalize(
                    $child
                );
        }

        return $object;
    }
}
