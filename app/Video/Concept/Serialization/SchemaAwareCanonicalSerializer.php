<?php

declare(strict_types=1);

namespace App\Video\Concept\Serialization;

use App\Video\Concept\Canonical\CanonicalDesignSpec;
use App\Video\Concept\Schema\EffectiveConceptSchema;
use JsonException;
use stdClass;

final class SchemaAwareCanonicalSerializer implements CanonicalJsonSerializer
{
    public const VERSION = 'canonical-json-v1';

    public function __construct(
        private readonly JsonSchemaTypeResolver $typeResolver,
    ) {}

    public function serialize(
        CanonicalDesignSpec $spec,
        EffectiveConceptSchema $schema,
    ): string {
        $data =
            $spec->toArray();

        $normalized =
            $this->convert(
                value: $data,

                nodeSchema: $schema->schema,

                rootSchema: $schema->schema,

                path: '$',
            );

        try {
            return json_encode(
                $normalized,
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_PRESERVE_ZERO_FRACTION
            );
        } catch (JsonException $e) {
            throw new CanonicalSerializationException(
                'Unable to encode canonical JSON: '
                .$e->getMessage(),
                previous: $e,
            );
        }
    }

    /**
     * @param  array<string,mixed>  $nodeSchema
     * @param  array<string,mixed>  $rootSchema
     */
    private function convert(
        mixed $value,
        array $nodeSchema,
        array $rootSchema,
        string $path,
    ): mixed {
        $schema =
            $this->typeResolver
                ->resolveNode(
                    rootSchema: $rootSchema,

                    nodeSchema: $nodeSchema,

                    value: $value,
                );

        $type =
            $schema['type']
            ?? null;

        if (! is_string($type)) {
            /*
             * const-only schemas nhu:
             *
             * "type": {"const":"count"}
             *
             * khong nhat thiet co "type":"string".
             */
            if (array_key_exists('const', $schema)) {
                return $this->convertConst(
                    value: $value,

                    const: $schema['const'],

                    path: $path,
                );
            }

            throw new CanonicalSerializationException(
                "Schema type is unresolved at {$path}."
            );
        }

        return match ($type) {
            'object' => $this->convertObject(
                value: $value,

                schema: $schema,

                rootSchema: $rootSchema,

                path: $path,
            ),

            'array' => $this->convertArray(
                value: $value,

                schema: $schema,

                rootSchema: $rootSchema,

                path: $path,
            ),

            'string' => $this->convertString(
                $value,
                $path
            ),

            'integer' => $this->convertInteger(
                $value,
                $path
            ),

            'number' => $this->convertNumber(
                $value,
                $path
            ),

            'boolean' => $this->convertBoolean(
                $value,
                $path
            ),

            'null' => $this->convertNull(
                $value,
                $path
            ),

            default => throw new CanonicalSerializationException(
                sprintf(
                    'Unsupported schema type "%s" at %s.',
                    $type,
                    $path
                )
            ),
        };
    }

    /**
     * @param  array<string,mixed>  $schema
     * @param  array<string,mixed>  $rootSchema
     */
    private function convertObject(
        mixed $value,
        array $schema,
        array $rootSchema,
        string $path,
    ): stdClass {
        if (! is_array($value)) {
            throw new CanonicalSerializationException(
                "Expected object-compatible array at {$path}."
            );
        }

        $properties =
            $schema['properties']
            ?? [];

        if (! is_array($properties)) {
            throw new CanonicalSerializationException(
                "Object schema properties invalid at {$path}."
            );
        }

        $additionalProperties =
            $schema['additionalProperties']
            ?? true;

        $result = [];

        foreach ($value as $key => $child) {
            if (! is_string($key)) {
                throw new CanonicalSerializationException(
                    "Object key must be string at {$path}."
                );
            }

            $childPath =
                $path.'.'.$key;

            if (isset($properties[$key])) {
                if (! is_array($properties[$key])) {
                    throw new CanonicalSerializationException(
                        "Invalid property schema at {$childPath}."
                    );
                }

                $result[$key] =
                    $this->convert(
                        value: $child,

                        nodeSchema: $properties[$key],

                        rootSchema: $rootSchema,

                        path: $childPath,
                    );

                continue;
            }

            if ($additionalProperties === false) {
                throw new CanonicalSerializationException(
                    "Unknown property {$childPath}."
                );
            }

            /*
             * Production effective profiles cua ta
             * phai closed.
             *
             * Neu additionalProperties la schema,
             * van support.
             */
            if (is_array($additionalProperties)) {
                $result[$key] =
                    $this->convert(
                        value: $child,

                        nodeSchema: $additionalProperties,

                        rootSchema: $rootSchema,

                        path: $childPath,
                    );

                continue;
            }

            /*
             * Khong serialize schema-less
             * arbitrary value trong canonical path.
             */
            throw new CanonicalSerializationException(
                'Unresolved open object property at '
                .$childPath
            );
        }

        /*
         * Object key order la canonical set.
         */
        ksort(
            $result,
            SORT_STRING
        );

        $object =
            new stdClass;

        foreach ($result as $key => $child) {
            $object->{$key} =
                $child;
        }

        /*
         * Empty PHP [] tro thanh stdClass {}
         * vi schema noi day la object.
         */
        return $object;
    }

    /**
     * @param  array<string,mixed>  $schema
     * @param  array<string,mixed>  $rootSchema
     * @return array<int,mixed>
     */
    private function convertArray(
        mixed $value,
        array $schema,
        array $rootSchema,
        string $path,
    ): array {
        if (! is_array($value)) {
            throw new CanonicalSerializationException(
                "Expected array at {$path}."
            );
        }

        /*
         * JSON array phai la PHP list.
         *
         * Khong tu array_values() mot associative
         * array vi nhu vay se che loi data.
         */
        if (! array_is_list($value)) {
            throw new CanonicalSerializationException(
                "Expected list array at {$path}."
            );
        }

        $itemsSchema =
            $schema['items']
            ?? null;

        if (! is_array($itemsSchema)) {
            throw new CanonicalSerializationException(
                "Array items schema missing at {$path}."
            );
        }

        $result = [];

        foreach (
            $value as $index => $child
        ) {
            $result[] =
                $this->convert(
                    value: $child,

                    nodeSchema: $itemsSchema,

                    rootSchema: $rootSchema,

                    path: $path
                        .'['
                        .$index
                        .']',
                );
        }

        /*
         * List order duoc giu nguyen.
         *
         * Serializer khong sort arrays.
         */
        return $result;
    }

    private function convertString(
        mixed $value,
        string $path,
    ): string {
        if (! is_string($value)) {
            throw new CanonicalSerializationException(
                "Expected string at {$path}."
            );
        }

        return $value;
    }

    private function convertInteger(
        mixed $value,
        string $path,
    ): int {
        if (! is_int($value)) {
            throw new CanonicalSerializationException(
                "Expected integer at {$path}."
            );
        }

        return $value;
    }

    private function convertNumber(
        mixed $value,
        string $path,
    ): float {
        if (
            ! is_int($value)
            && ! is_float($value)
        ) {
            throw new CanonicalSerializationException(
                "Expected number at {$path}."
            );
        }

        if (! is_finite((float) $value)) {
            throw new CanonicalSerializationException(
                "Non-finite number at {$path}."
            );
        }

        /*
         * Canonical-json-v1 policy:
         *
         * JSON Schema number -> PHP float.
         *
         * Nho vay:
         *
         * 6
         * 6.0
         *
         * deu canonicalize thanh cung numeric
         * representation 6.0.
         */
        return (float) $value;
    }

    private function convertBoolean(
        mixed $value,
        string $path,
    ): bool {
        if (! is_bool($value)) {
            throw new CanonicalSerializationException(
                "Expected boolean at {$path}."
            );
        }

        return $value;
    }

    /*
     * PHP 8.1: `null` chua dung duoc lam kieu tra ve doc lap (phai 8.2+),
     * nen dung `mixed`. Than ham van chi tra ve null.
     */
    private function convertNull(
        mixed $value,
        string $path,
    ): mixed {
        if ($value !== null) {
            throw new CanonicalSerializationException(
                "Expected null at {$path}."
            );
        }

        return null;
    }

    private function convertConst(
        mixed $value,
        mixed $const,
        string $path,
    ): mixed {
        if ($value !== $const) {
            throw new CanonicalSerializationException(
                sprintf(
                    'Const mismatch at %s.',
                    $path
                )
            );
        }

        return $value;
    }
}
