<?php

declare(strict_types=1);

namespace App\Video\Concept\Claude;

use RuntimeException;

final class ClaudeSchemaAdapter
{
    private const MAX_UNION_TYPED_PARAMETERS = 9;

    private const RESERVED_PROVIDER_UNIONS = 2;

    private int $nullableUnionBudget = 0;

    /**
     * @var list<string>
     */
    private const REMOVED_KEYWORDS = [
        'minimum',
        'maximum',
        'exclusiveMinimum',
        'exclusiveMaximum',
        'multipleOf',

        'minLength',
        'maxLength',

        'minItems',
        'maxItems',
        'uniqueItems',

        'minProperties',
        'maxProperties',

        'pattern',
    ];

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
     * Claude structured output bien schema thanh grammar. Core schema canonical
     * co nhieu object domain sau; Laravel se validate chung sau khi provider tra
     * JSON, nen o bien provider chi giu khung bat buoc va cac kieu JSON lon.
     *
     * @param  array<string,mixed>  $schema
     * @return array<string,mixed>
     */
    private function compactProviderGrammar(
        array $schema
    ): array {
        $properties =
            $schema['properties']
            ?? null;

        if (! is_array($properties)) {
            return $schema;
        }

        if (isset($properties['relationships'])) {
            $properties['relationships'] =
                $this->relationshipListSchema();
        }

        if (isset($properties['invariants'])) {
            $properties['invariants'] =
                $this->invariantListSchema();
        }

        if (isset($properties['exclusions'])) {
            $properties['exclusions'] =
                $this->exclusionListSchema();
        }

        if (isset($properties['provenance'])) {
            $properties['provenance'] =
                $this->provenanceListSchema();
        }

        $schema['properties'] = $properties;

        return $schema;
    }

    /** @return array<string,mixed> */
    private function jsonObjectSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [],
            'properties' => new \stdClass,
        ];
    }

    /** @return array<string,mixed> */
    private function relationshipListSchema(): array
    {
        return [
            'type' => 'array',
            'items' => ['type' => 'string'],
        ];
    }

    /** @return array<string,mixed> */
    private function invariantListSchema(): array
    {
        return [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => [
                    'id',
                    'name',
                    'source_path',
                    'constraint_type',
                    'severity',
                    'visual_verification',
                ],
                'properties' => [
                    'id' => ['type' => 'string'],
                    'name' => ['type' => 'string'],
                    'source_path' => ['type' => 'string'],
                    'constraint_type' => ['type' => 'string'],
                    'severity' => ['type' => 'string'],
                    'visual_verification' => ['type' => 'boolean'],
                ],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function exclusionListSchema(): array
    {
        return [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => [
                    'id',
                    'target',
                    'forbid',
                ],
                'properties' => [
                    'id' => ['type' => 'string'],
                    'target' => ['type' => 'string'],
                    'forbid' => ['type' => 'string'],
                ],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function provenanceListSchema(): array
    {
        return [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => [
                    'target_path',
                    'origin',
                    'source_aspects',
                ],
                'properties' => [
                    'target_path' => ['type' => 'string'],
                    'origin' => ['type' => 'string'],
                    'source_aspects' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
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

    /**
     * @param  array<string,mixed>  $root
     */
    private function walk(
        mixed $value,
        array $root
    ): mixed {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(
                fn (mixed $child): mixed => $this->walk($child, $root),

                $value
            );
        }

        if (isset($value['$ref'])) {
            return $this->walk(
                $this->resolveRef(
                    (string) $value['$ref'],
                    $root
                ),
                $root
            );
        }

        /*
         * `oneOf` KHONG duoc structured output chap nhan, nhung `anyOf` thi CO.
         * Nen chi doi ten khoa — KHONG gop 11 bien the lam mot.
         *
         * Gop lai se lam mat `items[]`/`members[]`, mat `axis`, va ha `value`
         * cua count/proportion tu integer/number xuong kieu chung. No cung
         * chinh la thu de ra 18 optional (hop cua moi bien the, chi 2 truong
         * bat buoc) — du mot minh no da vuot han muc cua provider.
         */
        if (isset($value['oneOf'])) {
            $value['anyOf'] = $value['oneOf'];

            unset($value['oneOf']);
        }

        $result = [];

        foreach (
            $value as $key => $child
        ) {
            if (
                in_array(
                    $key,
                    self::REMOVED_KEYWORDS,
                    true
                )
            ) {
                continue;
            }

            if ($key === '$defs') {
                continue;
            }

            if ($key === 'const') {
                $result['enum'] = [$child];

                continue;
            }

            $result[$key] =
                $this->walk($child, $root);
        }

        if ($this->isRelationshipUnion($result)) {
            return $this->compactRelationshipItemSchema();
        }

        return $this->requireEveryProperty($result);
    }

    /**
     * @param  array<string,mixed>  $node
     */
    private function isRelationshipUnion(
        array $node
    ): bool {
        $variants =
            $node['anyOf']
            ?? null;

        if (
            ! is_array($variants)
            || count($variants) !== 11
        ) {
            return false;
        }

        $types = [];

        foreach ($variants as $variant) {
            if (! is_array($variant)) {
                return false;
            }

            $type =
                $variant['properties']['type']['enum'][0]
                ?? null;

            if (! is_string($type)) {
                return false;
            }

            $types[] = $type;
        }

        sort($types);

        return $types === [
            'alignment',
            'connectivity',
            'containment',
            'continuity',
            'count',
            'grouping',
            'one_to_one',
            'order',
            'position',
            'proportion',
            'symmetry',
        ];
    }

    /**
     * Claude structured output rejects the full 11-way relationship union as a
     * compiled grammar. This is the provider-only shape; CanonicalJsonPayloadFactory
     * expands it back to the canonical per-type contract before validation.
     *
     * @return array<string,mixed>
     */
    private function compactRelationshipItemSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'id',
                'type',
            ],
            'properties' => [
                'id' => ['type' => 'string'],
                'type' => [
                    'type' => 'string',
                    'enum' => [
                        'count',
                        'grouping',
                        'one_to_one',
                        'proportion',
                        'position',
                        'order',
                        'continuity',
                        'connectivity',
                        'symmetry',
                        'containment',
                        'alignment',
                    ],
                ],
                'subject_path' => ['type' => 'string'],
                'source_path' => ['type' => 'string'],
                'target_path' => ['type' => 'string'],
                'reference_path' => ['type' => 'string'],
                'container_path' => ['type' => 'string'],
                'contained_path' => ['type' => 'string'],
                'metric' => ['type' => 'string'],
                'value' => ['type' => 'string'],
                'number_value' => ['type' => 'number'],
                'integer_value' => ['type' => 'integer'],
                'group_count' => ['type' => 'integer'],
                'items' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'members' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'axis' => ['type' => 'string'],
                'direction' => ['type' => 'string'],
                'start_path' => ['type' => 'string'],
                'end_path' => ['type' => 'string'],
                'grouped_into_path' => ['type' => 'string'],
                'meaning' => ['type' => 'string'],
            ],
        ];
    }

    /**
     * Provider tu choi schema co qua nhieu optional (dem: property khong nam
     * trong `required`, cong don toan schema). Gioi han do KHONG co trong tai
     * lieu, nen dung thiet ke bam sat no.
     *
     * Provider cung co gioi han rieng cho union/anyOf. V1 relationship schema
     * da dung mot anyOf bat buoc, nen chi nullable mot phan optional fields;
     * phan con lai van dua vao `required` nhung giu schema goc. Ket qua can
     * bang ca hai tran:
     *
     * - optional ve 0
     * - union/anyOf khong vuot 16
     *
     * Chieu ve do CanonicalJsonPayloadFactory lo: no bo moi khoa mang gia tri
     * `null` truoc khi bat cu validator nao nhin thay. Core schema khong co
     * truong nullable nao nen phep bo do khong nhap nhang.
     *
     * @param  array<string,mixed>  $node
     * @return array<string,mixed>
     */
    private function requireEveryProperty(
        array $node
    ): array {
        if (
            ($node['type'] ?? null) !== 'object'
        ) {
            return $node;
        }

        $properties =
            $node['properties']
            ?? null;

        if (
            ! is_array($properties)
            || $properties === []
        ) {
            return $node;
        }

        $required = is_array($node['required'] ?? null)
            ? $node['required']
            : [];

        foreach (
            $properties as $key => $schema
        ) {
            if (
                in_array($key, $required, true)
            ) {
                continue;
            }

            if ($this->nullableUnionBudget > 0) {
                $properties[$key] =
                    $this->nullableSchema($schema);

                $this->nullableUnionBudget--;
            }

            $required[] = $key;
        }

        $node['properties'] = $properties;

        $node['required'] = array_values(
            array_unique(
                $required
            )
        );

        return $node;
    }

    /**
     * @param  mixed  $schema
     * @return array<string,mixed>
     */
    private function nullableSchema(
        mixed $schema
    ): array {
        if (! is_array($schema)) {
            throw new RuntimeException(
                'Optional property schema must be an object.'
            );
        }

        return [
            'anyOf' => [
                $schema,
                ['type' => 'null'],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $root
     * @return array<string,mixed>
     */
    private function resolveRef(
        string $ref,
        array $root
    ): array {
        if (! str_starts_with($ref, '#/$defs/')) {
            throw new RuntimeException(
                "Unsupported schema ref {$ref}."
            );
        }

        $name =
            substr($ref, strlen('#/$defs/'));

        $resolved =
            $root['$defs'][$name]
            ?? null;

        if (! is_array($resolved)) {
            throw new RuntimeException(
                "Schema ref {$ref} cannot be resolved."
            );
        }

        return $resolved;
    }
}
