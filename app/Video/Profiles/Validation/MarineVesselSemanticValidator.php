<?php

declare(strict_types=1);

namespace App\Video\Profiles\Validation;

use App\Video\Concept\Canonical\CanonicalDesignSpec;
use App\Video\Concept\Canonical\Relationships\CountRelationship;
use App\Video\Concept\ConceptInput;
use App\Video\Concept\Validation\ValidationError;
use App\Video\Concept\Validation\ValidationResult;

final class MarineVesselSemanticValidator implements CategorySemanticValidator
{
    private const RATIO_TOLERANCE = 0.05;

    public function profileKey(): string
    {
        return 'marine_vessel';
    }

    public function version(): string
    {
        return '1.0';
    }

    public function validate(
        CanonicalDesignSpec $spec,
        ConceptInput $input,
    ): ValidationResult {
        $errors = [];

        $this->validateDimensions($spec, $errors);
        $this->validateSuperstructureCounts($spec, $errors);
        $this->validateGeometryPresence($spec, $errors);

        return ValidationResult::invalid($errors);
    }

    /**
     * @param  list<ValidationError>  $errors
     */
    private function validateDimensions(
        CanonicalDesignSpec $spec,
        array &$errors,
    ): void {
        $length = $spec->dimensions['length_m'] ?? null;
        $beam = $spec->dimensions['beam_m'] ?? null;
        $draft = $spec->dimensions['draft_m'] ?? null;
        $declaredRatio = $spec->dimensions['length_to_beam_ratio'] ?? null;

        if (
            ! is_numeric($length)
            || ! is_numeric($beam)
        ) {
            return;
        }

        $length = (float) $length;
        $beam = (float) $beam;

        if ($length <= 0.0) {
            $errors[] = new ValidationError(
                code: 'marine.dimensions.length_non_positive',
                path: 'dimensions.length_m',
                message: 'Marine vessel length_m must be greater than zero.',
                expected: '> 0',
                actual: $length,
            );
        }

        if ($beam <= 0.0) {
            $errors[] = new ValidationError(
                code: 'marine.dimensions.beam_non_positive',
                path: 'dimensions.beam_m',
                message: 'Marine vessel beam_m must be greater than zero.',
                expected: '> 0',
                actual: $beam,
            );

            return;
        }

        if (
            is_numeric($draft)
            && (float) $draft <= 0.0
        ) {
            $errors[] = new ValidationError(
                code: 'marine.dimensions.draft_non_positive',
                path: 'dimensions.draft_m',
                message: 'Marine vessel draft_m must be greater than zero.',
                expected: '> 0',
                actual: $draft,
            );
        }

        if (
            $length > 0.0
            && is_numeric($declaredRatio)
        ) {
            $computed = $length / $beam;
            $declared = (float) $declaredRatio;

            if (abs($computed - $declared) > self::RATIO_TOLERANCE) {
                $errors[] = new ValidationError(
                    code: 'marine.dimensions.length_to_beam_ratio_mismatch',
                    path: 'dimensions.length_to_beam_ratio',
                    message: 'Declared length_to_beam_ratio does not match length_m / beam_m.',
                    expected: round($computed, 4),
                    actual: $declared,
                );
            }
        }
    }

    /**
     * @param  list<ValidationError>  $errors
     */
    private function validateSuperstructureCounts(
        CanonicalDesignSpec $spec,
        array &$errors,
    ): void {
        $tierCount =
            $spec->permanentGeometry['superstructure']['primary_tier_count']
            ?? null;

        if (! is_int($tierCount)) {
            return;
        }

        foreach ($spec->relationships as $index => $relationship) {
            if (! $relationship instanceof CountRelationship) {
                continue;
            }

            if (
                $relationship->subjectPath
                !== 'permanent_geometry.superstructure.primary_tier_count'
            ) {
                continue;
            }

            if ($relationship->value !== $tierCount) {
                $errors[] = new ValidationError(
                    code: 'marine.superstructure.tier_count_relationship_mismatch',
                    path: "relationships.{$index}.value",
                    message: 'Superstructure count relationship conflicts with primary_tier_count.',
                    expected: $tierCount,
                    actual: $relationship->value,
                );
            }
        }
    }

    /**
     * @param  list<ValidationError>  $errors
     */
    private function validateGeometryPresence(
        CanonicalDesignSpec $spec,
        array &$errors,
    ): void {
        foreach (['hull', 'bow', 'stern', 'superstructure'] as $section) {
            $value = $spec->permanentGeometry[$section] ?? null;

            if (
                ! is_array($value)
                || $value === []
            ) {
                $errors[] = new ValidationError(
                    code: 'marine.geometry.empty_section',
                    path: 'permanent_geometry.'.$section,
                    message: sprintf(
                        'Marine vessel permanent_geometry.%s must not be empty.',
                        $section,
                    ),
                    expected: 'non-empty object',
                    actual: $value,
                );
            }
        }
    }
}
