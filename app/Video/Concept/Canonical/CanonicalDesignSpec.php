<?php

declare(strict_types=1);

namespace App\Video\Concept\Canonical;

use App\Video\Concept\Canonical\Relationships\Relationship;
use App\Video\Concept\Canonical\Relationships\RelationshipFactory;
use InvalidArgumentException;

final class CanonicalDesignSpec
{
    public const SCHEMA_VERSION = '1.0';

    /**
     * @param  array<string,mixed>  $dimensions
     * @param  array<string,mixed>  $permanentGeometry
     * @param  list<Relationship>  $relationships
     * @param  array<string,mixed>  $formRelationships
     * @param  array<string,mixed>  $finishedMaterials
     * @param  list<Exclusion>  $exclusions
     * @param  list<Invariant>  $invariants
     * @param  list<ProvenanceEntry>  $provenance
     */
    public function __construct(
        public readonly string $schemaVersion,
        public readonly string $objectType,
        public readonly DesignThesis $designThesis,
        public readonly DesignIdentity $identity,

        public readonly array $dimensions,

        public readonly array $permanentGeometry,

        public readonly array $relationships,

        public readonly array $formRelationships,

        public readonly array $finishedMaterials,

        public readonly array $exclusions,

        public readonly array $invariants,

        public readonly array $provenance,
    ) {
        if (
            $schemaVersion
            !== self::SCHEMA_VERSION
        ) {
            throw new InvalidArgumentException(
                'schema_version must equal '
                .self::SCHEMA_VERSION
            );
        }

        if (trim($objectType) === '') {
            throw new InvalidArgumentException(
                'object_type must not be empty.'
            );
        }

        if ($invariants === []) {
            throw new InvalidArgumentException(
                'invariants must contain '
                .'at least one item.'
            );
        }
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public static function fromArray(
        array $data
    ): self {
        return new self(
            schemaVersion: (string) (
                $data['schema_version'] ?? ''
            ),

            objectType: (string) (
                $data['object_type'] ?? ''
            ),

            designThesis: DesignThesis::fromArray(
                self::object(
                    $data,
                    'design_thesis'
                )
            ),

            identity: DesignIdentity::fromArray(
                self::object(
                    $data,
                    'identity'
                )
            ),

            dimensions: self::object(
                $data,
                'dimensions'
            ),

            permanentGeometry: self::object(
                $data,
                'permanent_geometry'
            ),

            relationships: array_map(
                static fn (
                    array $item
                ): Relationship => RelationshipFactory::fromArray(
                    $item
                ),

                self::objectList(
                    $data,
                    'relationships'
                ),
            ),

            formRelationships: self::object(
                $data,
                'form_relationships'
            ),

            finishedMaterials: self::object(
                $data,
                'finished_materials'
            ),

            exclusions: array_map(
                static fn (
                    array $item
                ): Exclusion => Exclusion::fromArray(
                    $item
                ),

                self::objectList(
                    $data,
                    'exclusions'
                ),
            ),

            invariants: array_map(
                static fn (
                    array $item
                ): Invariant => Invariant::fromArray(
                    $item
                ),

                self::objectList(
                    $data,
                    'invariants'
                ),
            ),

            provenance: array_map(
                static fn (
                    array $item
                ): ProvenanceEntry => ProvenanceEntry::fromArray(
                    $item
                ),

                self::objectList(
                    $data,
                    'provenance'
                ),
            ),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,

            'object_type' => $this->objectType,

            'design_thesis' => $this->designThesis->toArray(),

            'identity' => $this->identity->toArray(),

            'dimensions' => $this->dimensions,

            'permanent_geometry' => $this->permanentGeometry,

            'relationships' => array_map(
                static fn (
                    Relationship $item
                ): array => $item->toArray(),

                $this->relationships,
            ),

            'form_relationships' => $this->formRelationships,

            'finished_materials' => $this->finishedMaterials,

            'exclusions' => array_map(
                static fn (
                    Exclusion $item
                ): array => $item->toArray(),

                $this->exclusions,
            ),

            'invariants' => array_map(
                static fn (
                    Invariant $item
                ): array => $item->toArray(),

                $this->invariants,
            ),

            'provenance' => array_map(
                static fn (
                    ProvenanceEntry $item
                ): array => $item->toArray(),

                $this->provenance,
            ),
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private static function object(
        array $data,
        string $key
    ): array {
        $value = $data[$key] ?? null;

        if (! is_array($value)) {
            throw new InvalidArgumentException(
                "{$key} must be an object."
            );
        }

        /*
         * IMPORTANT:
         *
         * PHP cannot distinguish an empty JSON object {}
         * from an empty associative array after decoding
         * with json_decode(..., true).
         *
         * Therefore [] is accepted here for an EMPTY
         * domain-open object. JSON Schema validation has
         * already been performed against the raw JSON
         * before hydration.
         *
         * Non-empty list arrays remain invalid objects.
         */
        if (
            $value !== []
            && array_is_list($value)
        ) {
            throw new InvalidArgumentException(
                "{$key} must be an object."
            );
        }

        return $value;
    }

    /**
     * @param  array<string,mixed>  $data
     * @return list<array<string,mixed>>
     */
    private static function objectList(
        array $data,
        string $key
    ): array {
        $value = $data[$key] ?? null;

        if (
            ! is_array($value)
            || ! array_is_list($value)
        ) {
            throw new InvalidArgumentException(
                "{$key} must be a list."
            );
        }

        foreach (
            $value as $index => $item
        ) {
            if (
                ! is_array($item)
                || $item === []
                || array_is_list($item)
            ) {
                throw new InvalidArgumentException(
                    "{$key}[{$index}] "
                    .'must be an object.'
                );
            }
        }

        return array_values($value);
    }
}
