<?php

declare(strict_types=1);

namespace App\Video\Concept\Serialization;

final class JsonSchemaTypeResolver
{
    /**
     * @param  array<string,mixed>  $rootSchema
     * @param  array<string,mixed>  $nodeSchema
     * @return array<string,mixed>
     */
    public function resolveNode(
        array $rootSchema,
        array $nodeSchema,
        mixed $value,
    ): array {
        $resolved =
            $this->resolveRef(
                rootSchema: $rootSchema,
                nodeSchema: $nodeSchema,
            );

        if (isset($resolved['oneOf'])) {
            return $this->resolveOneOf(
                rootSchema: $rootSchema,
                nodeSchema: $resolved,
                value: $value,
            );
        }

        return $resolved;
    }

    /**
     * @param  array<string,mixed>  $rootSchema
     * @param  array<string,mixed>  $nodeSchema
     * @return array<string,mixed>
     */
    private function resolveRef(
        array $rootSchema,
        array $nodeSchema,
    ): array {
        $ref =
            $nodeSchema['$ref']
            ?? null;

        if ($ref === null) {
            return $nodeSchema;
        }

        if (! is_string($ref)) {
            throw new CanonicalSerializationException(
                'JSON Schema $ref must be a string.'
            );
        }

        if (! str_starts_with($ref, '#/')) {
            throw new CanonicalSerializationException(
                'Only local JSON Schema references are supported: '
                .$ref
            );
        }

        $segments =
            explode(
                '/',
                substr($ref, 2)
            );

        $current =
            $rootSchema;

        foreach ($segments as $segment) {
            $segment =
                str_replace(
                    ['~1', '~0'],
                    ['/', '~'],
                    $segment
                );

            if (
                ! is_array($current)
                || ! array_key_exists(
                    $segment,
                    $current
                )
            ) {
                throw new CanonicalSerializationException(
                    'Unable to resolve JSON Schema reference: '
                    .$ref
                );
            }

            $current =
                $current[$segment];
        }

        if (
            ! is_array($current)
            || array_is_list($current)
        ) {
            throw new CanonicalSerializationException(
                'Resolved JSON Schema reference '
                .'must point to an object schema: '
                .$ref
            );
        }

        /*
         * Neu $ref co sibling keywords,
         * merge chung len resolved schema.
         */
        $siblings =
            $nodeSchema;

        unset($siblings['$ref']);

        return array_replace(
            $current,
            $siblings
        );
    }

    /**
     * @param  array<string,mixed>  $rootSchema
     * @param  array<string,mixed>  $nodeSchema
     * @return array<string,mixed>
     */
    private function resolveOneOf(
        array $rootSchema,
        array $nodeSchema,
        mixed $value,
    ): array {
        $branches =
            $nodeSchema['oneOf']
            ?? null;

        if (
            ! is_array($branches)
            || $branches === []
        ) {
            throw new CanonicalSerializationException(
                'oneOf must contain at least one schema.'
            );
        }

        /*
         * Canonical V1 relationships dung:
         *
         * {
         *   "type": {"const":"count"}
         * }
         *
         * nen discriminator "type" la lua chon
         * deterministic nhat.
         */
        if (
            is_array($value)
            && isset($value['type'])
            && is_string($value['type'])
        ) {
            foreach ($branches as $branch) {
                if (! is_array($branch)) {
                    continue;
                }

                $candidate =
                    $this->resolveNode(
                        rootSchema: $rootSchema,
                        nodeSchema: $branch,
                        value: $value,
                    );

                $const =
                    $candidate['properties']['type']['const']
                    ?? null;

                if (
                    is_string($const)
                    && $const === $value['type']
                ) {
                    return $candidate;
                }
            }
        }

        /*
         * Generic fallback:
         * tim branch co required/const tuong thich.
         */
        $matches = [];

        foreach ($branches as $branch) {
            if (! is_array($branch)) {
                continue;
            }

            $candidate =
                $this->resolveNode(
                    rootSchema: $rootSchema,
                    nodeSchema: $branch,
                    value: $value,
                );

            if (
                $this->structurallyMatches(
                    $candidate,
                    $value
                )
            ) {
                $matches[] =
                    $candidate;
            }
        }

        if (count($matches) === 1) {
            return $matches[0];
        }

        throw new CanonicalSerializationException(
            sprintf(
                'Unable to deterministically select oneOf branch; matches=%d.',
                count($matches)
            )
        );
    }

    /**
     * @param  array<string,mixed>  $schema
     */
    private function structurallyMatches(
        array $schema,
        mixed $value,
    ): bool {
        $type =
            $schema['type']
            ?? null;

        if (
            $type === 'object'
            && ! is_array($value)
        ) {
            return false;
        }

        if (
            $type === 'array'
            && ! is_array($value)
        ) {
            return false;
        }

        if (
            $type === 'object'
            && is_array($value)
        ) {
            $required =
                $schema['required']
                ?? [];

            if (is_array($required)) {
                foreach ($required as $key) {
                    if (
                        ! is_string($key)
                        || ! array_key_exists(
                            $key,
                            $value
                        )
                    ) {
                        return false;
                    }
                }
            }

            $properties =
                $schema['properties']
                ?? [];

            if (is_array($properties)) {
                foreach (
                    $properties as $key => $propertySchema
                ) {
                    if (
                        ! array_key_exists(
                            $key,
                            $value
                        )
                        || ! is_array($propertySchema)
                    ) {
                        continue;
                    }

                    if (
                        isset($propertySchema['const'])
                        && $value[$key]
                            !== $propertySchema['const']
                    ) {
                        return false;
                    }
                }
            }
        }

        return true;
    }
}
