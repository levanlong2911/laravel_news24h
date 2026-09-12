<?php

declare(strict_types=1);

namespace App\Video\Concept\Schema;

use RuntimeException;

final class JsonSchemaMerger
{
    /**
     * @param  array<string,mixed>  $core
     * @param  array<string,mixed>  $refinements
     * @param  list<string>  $allowedRootFields
     * @return array<string,mixed>
     */
    public function replaceRootRefinements(
        array $core,
        array $refinements,
        array $allowedRootFields,
    ): array {
        if (
            ! isset($core['properties'])
            || ! is_array($core['properties'])
        ) {
            throw new RuntimeException(
                'Canonical core schema has no properties object.'
            );
        }

        $this->assertOnlyAllowedRefinements(
            refinements: $refinements,
            allowedRootFields: $allowedRootFields,
        );

        foreach ($allowedRootFields as $field) {
            if (! array_key_exists($field, $refinements)) {
                throw new RuntimeException(
                    "Profile must refine \"{$field}\"."
                );
            }

            $refinement = $refinements[$field];

            if (
                ! is_array($refinement)
                || array_is_list($refinement)
            ) {
                throw new RuntimeException(
                    "Profile refinement {$field} must be a JSON object."
                );
            }

            $this->assertClosedObjectSchema(
                field: $field,
                schema: $refinement,
            );

            $core['properties'][$field] = $refinement;
        }

        return $core;
    }

    /**
     * @param  array<string,mixed>  $refinements
     * @param  list<string>  $allowedRootFields
     */
    private function assertOnlyAllowedRefinements(
        array $refinements,
        array $allowedRootFields,
    ): void {
        foreach (array_keys($refinements) as $field) {
            if (! in_array($field, $allowedRootFields, true)) {
                throw new RuntimeException(
                    'Profile attempts to refine a protected canonical field: '
                    .$field
                );
            }
        }
    }

    /**
     * @param  array<string,mixed>  $schema
     */
    private function assertClosedObjectSchema(
        string $field,
        array $schema,
    ): void {
        if (($schema['type'] ?? null) !== 'object') {
            throw new RuntimeException(
                "{$field} refinement must have type=object."
            );
        }

        if (($schema['additionalProperties'] ?? null) !== false) {
            throw new RuntimeException(
                "{$field} refinement must set additionalProperties=false."
            );
        }

        if (
            ! isset($schema['properties'])
            || ! is_array($schema['properties'])
        ) {
            throw new RuntimeException(
                "{$field} refinement must define properties."
            );
        }
    }
}
