<?php

declare(strict_types=1);

namespace App\Video\Concept\Canonical\Relationships;

use App\Video\Concept\Canonical\Enums\RelationshipType;
use InvalidArgumentException;

final class RelationshipFactory
{
    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(
        array $data
    ): Relationship {
        $type =
            RelationshipType::tryFrom(
                (string) (
                    $data['type'] ?? ''
                )
            );

        if ($type === null) {
            throw new InvalidArgumentException(
                'Unsupported relationship type: '
                . (string) (
                    $data['type'] ?? ''
                )
            );
        }

        return match ($type) {
            RelationshipType::COUNT =>
                new CountRelationship(
                    relationshipId:
                        (string) (
                            $data['id'] ?? ''
                        ),

                    subjectPath:
                        (string) (
                            $data['subject_path']
                            ?? ''
                        ),

                    value:
                        self::int(
                            $data,
                            'value'
                        ),
                ),

            RelationshipType::GROUPING =>
                new GroupingRelationship(
                    relationshipId:
                        (string) (
                            $data['id'] ?? ''
                        ),

                    subjectPath:
                        (string) (
                            $data['subject_path']
                            ?? ''
                        ),

                    groupCount:
                        self::int(
                            $data,
                            'group_count'
                        ),

                    groupedIntoPath:
                        self::nullableString(
                            $data,
                            'grouped_into_path'
                        ),

                    meaning:
                        self::nullableString(
                            $data,
                            'meaning'
                        ),
                ),

            RelationshipType::ONE_TO_ONE =>
                new OneToOneRelationship(
                    relationshipId:
                        (string) (
                            $data['id'] ?? ''
                        ),

                    sourcePath:
                        (string) (
                            $data['source_path']
                            ?? ''
                        ),

                    targetPath:
                        (string) (
                            $data['target_path']
                            ?? ''
                        ),

                    meaning:
                        self::nullableString(
                            $data,
                            'meaning'
                        ),
                ),

            RelationshipType::PROPORTION =>
                new ProportionRelationship(
                    relationshipId:
                        (string) (
                            $data['id'] ?? ''
                        ),

                    subjectPath:
                        (string) (
                            $data['subject_path']
                            ?? ''
                        ),

                    metric:
                        (string) (
                            $data['metric'] ?? ''
                        ),

                    value:
                        self::float(
                            $data,
                            'value'
                        ),

                    tolerance:
                        array_key_exists(
                            'tolerance',
                            $data
                        )
                            ? self::float(
                                $data,
                                'tolerance'
                            )
                            : null,
                ),

            RelationshipType::POSITION =>
                new PositionRelationship(
                    relationshipId:
                        (string) (
                            $data['id'] ?? ''
                        ),

                    subjectPath:
                        (string) (
                            $data['subject_path']
                            ?? ''
                        ),

                    referencePath:
                        (string) (
                            $data['reference_path']
                            ?? ''
                        ),

                    value:
                        (string) (
                            $data['value'] ?? ''
                        ),
                ),

            RelationshipType::ORDER =>
                new OrderRelationship(
                    relationshipId:
                        (string) (
                            $data['id'] ?? ''
                        ),

                    items:
                        self::stringList(
                            $data['items'] ?? []
                        ),

                    direction:
                        self::nullableString(
                            $data,
                            'direction'
                        ),
                ),

            RelationshipType::CONTINUITY =>
                new ContinuityRelationship(
                    relationshipId:
                        (string) (
                            $data['id'] ?? ''
                        ),

                    subjectPath:
                        (string) (
                            $data['subject_path']
                            ?? ''
                        ),

                    value:
                        (string) (
                            $data['value'] ?? ''
                        ),

                    startPath:
                        self::nullableString(
                            $data,
                            'start_path'
                        ),

                    endPath:
                        self::nullableString(
                            $data,
                            'end_path'
                        ),
                ),

            RelationshipType::CONNECTIVITY =>
                new ConnectivityRelationship(
                    relationshipId:
                        (string) (
                            $data['id'] ?? ''
                        ),

                    members:
                        self::stringList(
                            $data['members'] ?? []
                        ),

                    value:
                        (string) (
                            $data['value'] ?? ''
                        ),
                ),

            RelationshipType::SYMMETRY =>
                new SymmetryRelationship(
                    relationshipId:
                        (string) (
                            $data['id'] ?? ''
                        ),

                    subjectPath:
                        (string) (
                            $data['subject_path']
                            ?? ''
                        ),

                    axis:
                        (string) (
                            $data['axis'] ?? ''
                        ),

                    value:
                        (string) (
                            $data['value'] ?? ''
                        ),
                ),

            RelationshipType::CONTAINMENT =>
                new ContainmentRelationship(
                    relationshipId:
                        (string) (
                            $data['id'] ?? ''
                        ),

                    containerPath:
                        (string) (
                            $data['container_path']
                            ?? ''
                        ),

                    containedPath:
                        (string) (
                            $data['contained_path']
                            ?? ''
                        ),
                ),

            RelationshipType::ALIGNMENT =>
                new AlignmentRelationship(
                    relationshipId:
                        (string) (
                            $data['id'] ?? ''
                        ),

                    members:
                        self::stringList(
                            $data['members'] ?? []
                        ),

                    value:
                        (string) (
                            $data['value'] ?? ''
                        ),
                ),
        };
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function int(
        array $data,
        string $key
    ): int {
        if (
            ! array_key_exists(
                $key,
                $data
            )
            || ! is_int($data[$key])
        ) {
            throw new InvalidArgumentException(
                "{$key} must be an integer."
            );
        }

        return $data[$key];
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function float(
        array $data,
        string $key
    ): float {
        if (
            ! array_key_exists(
                $key,
                $data
            )
            || (
                ! is_int($data[$key])
                && ! is_float($data[$key])
            )
        ) {
            throw new InvalidArgumentException(
                "{$key} must be a number."
            );
        }

        return (float) $data[$key];
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function nullableString(
        array $data,
        string $key
    ): ?string {
        if (
            ! array_key_exists(
                $key,
                $data
            )
        ) {
            return null;
        }

        if (! is_string($data[$key])) {
            throw new InvalidArgumentException(
                "{$key} must be a string "
                . 'when present.'
            );
        }

        return $data[$key];
    }

    /**
     * @return list<string>
     */
    private static function stringList(
        mixed $value
    ): array {
        if (
            ! is_array($value)
            || ! array_is_list($value)
        ) {
            throw new InvalidArgumentException(
                'Expected a list of strings.'
            );
        }

        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new InvalidArgumentException(
                    'Expected a list of strings.'
                );
            }
        }

        return array_values($value);
    }
}
