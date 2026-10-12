<?php

declare(strict_types=1);

namespace App\Video\Concept\Claude;

use RuntimeException;

final class ClaudeSchemaAdapter
{
    /**
     * @param  array<string,mixed>  $effectiveSchema
     * @return array<string,mixed>
     */
    public function adapt(
        array $effectiveSchema
    ): array {
        /*
         * Truoc khi projection,
         * dam bao khong con cac domain-open
         * Core objects chua duoc profile refine.
         */
        $this->assertResolvedDomainObjects(
            $effectiveSchema
        );

        /*
         * Anthropic structured output bien schema thanh grammar. Canonical V1 +
         * category profile qua lon de compile truc tiep, nen provider chi bi
         * rang buoc bang envelope cuc nho. Ben trong `canonical_json` van phai
         * la JSON string dung canonical schema; Laravel validate bang Core +
         * Effective + Semantic ngay sau khi unwrap.
         */
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'canonical_json',
            ],
            'properties' => [
                'canonical_json' => [
                    'type' => 'string',
                ],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $schema
     */
    private function assertResolvedDomainObjects(
        array $schema
    ): void {
        $properties =
            $schema['properties']
            ?? null;

        if (! is_array($properties)) {
            throw new RuntimeException(
                'Effective schema properties '
                .'are missing.'
            );
        }

        foreach (
            [
                'dimensions',
                'permanent_geometry',
                'form_relationships',
                'finished_materials',
            ] as $field
        ) {
            $node =
                $properties[$field]
                ?? null;

            if (
                ! is_array($node)
                || ($node['type'] ?? null)
                    !== 'object'
            ) {
                throw new RuntimeException(
                    "Effective schema {$field} "
                    .'is not a valid object schema.'
                );
            }

            if (
                ($node['additionalProperties']
                    ?? null)
                !== false
            ) {
                throw new RuntimeException(
                    "Effective schema {$field} "
                    .'is still domain-open. '
                    .'A category profile must '
                    .'resolve it before sending '
                    .'structured output to Claude.'
                );
            }

            if (
                ! isset($node['properties'])
                || ! is_array(
                    $node['properties']
                )
            ) {
                throw new RuntimeException(
                    "Effective schema {$field} "
                    .'does not define properties.'
                );
            }
        }
    }
}
