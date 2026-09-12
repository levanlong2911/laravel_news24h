<?php

declare(strict_types=1);

namespace App\Video\Concept\Validation;

use App\Video\Concept\Canonical\CanonicalDesignSpec;
use App\Video\Concept\Canonical\Enums\ConstraintPrimitive;
use App\Video\Concept\Canonical\Relationships\AlignmentRelationship;
use App\Video\Concept\Canonical\Relationships\ConnectivityRelationship;
use App\Video\Concept\Canonical\Relationships\ContainmentRelationship;
use App\Video\Concept\Canonical\Relationships\ContinuityRelationship;
use App\Video\Concept\Canonical\Relationships\CountRelationship;
use App\Video\Concept\Canonical\Relationships\GroupingRelationship;
use App\Video\Concept\Canonical\Relationships\OneToOneRelationship;
use App\Video\Concept\Canonical\Relationships\OrderRelationship;
use App\Video\Concept\Canonical\Relationships\PositionRelationship;
use App\Video\Concept\Canonical\Relationships\ProportionRelationship;
use App\Video\Concept\Canonical\Relationships\Relationship;
use App\Video\Concept\Canonical\Relationships\SymmetryRelationship;

final class CanonicalCrossFieldValidator
{
    public function __construct(
        private readonly CanonicalPathValidator $paths,
    ) {
    }

    /**
     * @return list<ValidationError>
     */
    public function validate(
        CanonicalDesignSpec $spec
    ): array {
        $errors = [];

        $document =
            $spec->toArray();

        $errors = [
            ...$errors,
            ...$this->validateRelationshipIds(
                $spec
            ),
        ];

        $errors = [
            ...$errors,
            ...$this->validateRelationshipPaths(
                $spec,
                $document
            ),
        ];

        $errors = [
            ...$errors,
            ...$this->validateCountRelationships(
                $spec,
                $document
            ),
        ];

        $errors = [
            ...$errors,
            ...$this->validateInvariantIdsAndPaths(
                $spec,
                $document
            ),
        ];

        $errors = [
            ...$errors,
            ...$this->validateExclusionIdsAndPaths(
                $spec,
                $document
            ),
        ];

        return $errors;
    }

    /**
     * @return list<ValidationError>
     */
    private function validateRelationshipIds(
        CanonicalDesignSpec $spec
    ): array {
        $errors = [];
        $seen = [];

        foreach (
            $spec->relationships
            as $index => $relationship
        ) {
            $id = $relationship->id();

            if (isset($seen[$id])) {
                $errors[] =
                    new ValidationError(
                        code:
                            'duplicate_relationship_id',

                        path:
                            "relationships.{$index}.id",

                        message:
                            'Relationship ids '
                            . 'must be unique.',

                        actual:
                            $id,
                    );
            }

            $seen[$id] = true;
        }

        return $errors;
    }

    /**
     * @param array<string,mixed> $document
     *
     * @return list<ValidationError>
     */
    private function validateRelationshipPaths(
        CanonicalDesignSpec $spec,
        array $document
    ): array {
        $errors = [];

        foreach (
            $spec->relationships
            as $index => $relationship
        ) {
            foreach (
                $this->referencedPaths(
                    $relationship
                )
                as $field => $path
            ) {
                if (
                    !$this->paths->exists(
                        $document,
                        $path
                    )
                ) {
                    $errors[] =
                        new ValidationError(
                            code:
                                'relationship_path_missing',

                            path:
                                "relationships."
                                . "{$index}.{$field}",

                            message:
                                'Referenced canonical '
                                . 'path does not exist.',

                            expected:
                                'existing canonical path',

                            actual:
                                $path,
                        );
                }
            }
        }

        return $errors;
    }

    /**
     * V1 semantics:
     *
     * CountRelationship may directly reference an integer
     * count-bearing field.
     *
     * If subject_path resolves to an integer,
     * relationship.value must equal it.
     *
     * If subject_path resolves to an object/list,
     * this validator does NOT guess count semantics.
     * Category/profile validator may add stronger rules.
     *
     * @param array<string,mixed> $document
     *
     * @return list<ValidationError>
     */
    private function validateCountRelationships(
        CanonicalDesignSpec $spec,
        array $document
    ): array {
        $errors = [];

        foreach (
            $spec->relationships
            as $index => $relationship
        ) {
            if (
                !$relationship
                    instanceof CountRelationship
            ) {
                continue;
            }

            [
                $actual,
                $found,
            ] = $this->paths
                ->resolveWithStatus(
                    $document,
                    $relationship->subjectPath
                );

            if (!$found) {
                /*
                 * Path missing đã được
                 * validateRelationshipPaths()
                 * report rồi.
                 */
                continue;
            }

            if (!is_int($actual)) {
                /*
                 * Core V1 không tự đoán:
                 *
                 * array => count(array)?
                 * object => property count?
                 *
                 * Không.
                 *
                 * Việc đó thuộc profile semantics.
                 */
                continue;
            }

            if (
                $actual
                !== $relationship->value
            ) {
                $errors[] =
                    new ValidationError(
                        code:
                            'count_relationship_mismatch',

                        path:
                            "relationships."
                            . "{$index}.value",

                        message:
                            'Count relationship value '
                            . 'conflicts with the '
                            . 'referenced integer field.',

                        expected:
                            $actual,

                        actual:
                            $relationship->value,
                    );
            }
        }

        return $errors;
    }

    /**
     * @param array<string,mixed> $document
     *
     * @return list<ValidationError>
     */
    private function validateInvariantIdsAndPaths(
        CanonicalDesignSpec $spec,
        array $document
    ): array {
        $errors = [];
        $seen = [];

        foreach (
            $spec->invariants
            as $index => $invariant
        ) {
            if (isset($seen[$invariant->id])) {
                $errors[] =
                    new ValidationError(
                        code:
                            'duplicate_invariant_id',

                        path:
                            "invariants.{$index}.id",

                        message:
                            'Invariant ids '
                            . 'must be unique.',

                        actual:
                            $invariant->id,
                    );
            }

            $seen[$invariant->id] =
                true;

            if (
                !$this->paths->exists(
                    $document,
                    $invariant->sourcePath
                )
            ) {
                $errors[] =
                    new ValidationError(
                        code:
                            'invariant_source_path_missing',

                        path:
                            "invariants."
                            . "{$index}.source_path",

                        message:
                            'Invariant source_path '
                            . 'does not exist.',

                        expected:
                            'existing canonical path',

                        actual:
                            $invariant->sourcePath,
                    );

                continue;
            }

            [
                $value,
                $found,
            ] = $this->paths
                ->resolveWithStatus(
                    $document,
                    $invariant->sourcePath
                );

            if (
                $found
                && $invariant->constraintType
                    === ConstraintPrimitive::COUNT
                && ! is_int($value)
                && ! is_array($value)
            ) {
                $errors[] =
                    new ValidationError(
                        code:
                            'invariant_constraint_type_mismatch',

                        path:
                            "invariants."
                            . "{$index}.constraint_type",

                        message:
                            'Count invariant must point '
                            . 'at an integer or countable '
                            . 'structure.',

                        expected:
                            'integer or array',

                        actual:
                            get_debug_type($value),
                    );
            }
        }

        return $errors;
    }

    /**
     * @param array<string,mixed> $document
     *
     * @return list<ValidationError>
     */
    private function validateExclusionIdsAndPaths(
        CanonicalDesignSpec $spec,
        array $document
    ): array {
        $errors = [];
        $seen = [];

        foreach (
            $spec->exclusions
            as $index => $exclusion
        ) {
            if (isset($seen[$exclusion->id])) {
                $errors[] =
                    new ValidationError(
                        code:
                            'duplicate_exclusion_id',

                        path:
                            "exclusions.{$index}.id",

                        message:
                            'Exclusion ids '
                            . 'must be unique.',

                        actual:
                            $exclusion->id,
                    );
            }

            $seen[$exclusion->id] =
                true;

            if (
                !$this->paths->exists(
                    $document,
                    $exclusion->targetPath
                )
            ) {
                $errors[] =
                    new ValidationError(
                        code:
                            'exclusion_target_path_missing',

                        path:
                            "exclusions."
                            . "{$index}.target_path",

                        message:
                            'Exclusion target_path '
                            . 'does not exist.',

                        expected:
                            'existing canonical path',

                        actual:
                            $exclusion->targetPath,
                    );
            }
        }

        return $errors;
    }

    /**
     * Trả về tất cả canonical paths mà
     * relationship đang reference.
     *
     * Key = field trong relationship.
     * Value = canonical path.
     *
     * @return array<string,string>
     */
    private function referencedPaths(
        Relationship $relationship
    ): array {
        if (
            $relationship
                instanceof CountRelationship
        ) {
            return [
                'subject_path' =>
                    $relationship->subjectPath,
            ];
        }

        if (
            $relationship
                instanceof GroupingRelationship
        ) {
            $paths = [
                'subject_path' =>
                    $relationship->subjectPath,
            ];

            if (
                $relationship
                    ->groupedIntoPath
                !== null
            ) {
                $paths['grouped_into_path'] =
                    $relationship
                        ->groupedIntoPath;
            }

            return $paths;
        }

        if (
            $relationship
                instanceof OneToOneRelationship
        ) {
            return [
                'source_path' =>
                    $relationship->sourcePath,

                'target_path' =>
                    $relationship->targetPath,
            ];
        }

        if (
            $relationship
                instanceof ProportionRelationship
        ) {
            return [
                'subject_path' =>
                    $relationship->subjectPath,
            ];
        }

        if (
            $relationship
                instanceof PositionRelationship
        ) {
            return [
                'subject_path' =>
                    $relationship->subjectPath,

                'reference_path' =>
                    $relationship->referencePath,
            ];
        }

        if (
            $relationship
                instanceof OrderRelationship
        ) {
            $paths = [];

            foreach (
                $relationship->items
                as $itemIndex => $path
            ) {
                $paths[
                    "items.{$itemIndex}"
                ] = $path;
            }

            return $paths;
        }

        if (
            $relationship
                instanceof ContinuityRelationship
        ) {
            $paths = [
                'subject_path' =>
                    $relationship->subjectPath,
            ];

            if (
                $relationship->startPath
                !== null
            ) {
                $paths['start_path'] =
                    $relationship->startPath;
            }

            if (
                $relationship->endPath
                !== null
            ) {
                $paths['end_path'] =
                    $relationship->endPath;
            }

            return $paths;
        }

        if (
            $relationship
                instanceof ConnectivityRelationship
        ) {
            $paths = [];

            foreach (
                $relationship->members
                as $memberIndex => $path
            ) {
                $paths[
                    "members.{$memberIndex}"
                ] = $path;
            }

            return $paths;
        }

        if (
            $relationship
                instanceof SymmetryRelationship
        ) {
            return [
                'subject_path' =>
                    $relationship->subjectPath,
            ];
        }

        if (
            $relationship
                instanceof ContainmentRelationship
        ) {
            return [
                'container_path' =>
                    $relationship->containerPath,

                'contained_path' =>
                    $relationship->containedPath,
            ];
        }

        if (
            $relationship
                instanceof AlignmentRelationship
        ) {
            $paths = [];

            foreach (
                $relationship->members
                as $memberIndex => $path
            ) {
                $paths[
                    "members.{$memberIndex}"
                ] = $path;
            }

            return $paths;
        }

        return [];
    }
}
