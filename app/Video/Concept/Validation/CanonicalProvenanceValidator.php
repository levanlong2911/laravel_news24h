<?php

declare(strict_types=1);

namespace App\Video\Concept\Validation;

use App\Video\Concept\Canonical\CanonicalDesignSpec;
use App\Video\Concept\Canonical\Enums\ProvenanceOrigin;
use App\Video\Inspiration\InspirationBrief;

final class CanonicalProvenanceValidator
{
    public function __construct(
        private readonly CanonicalPathValidator $paths,
    ) {
    }

    /**
     * @return list<ValidationError>
     */
    public function validate(
        CanonicalDesignSpec $spec,
        InspirationBrief $brief
    ): array {
        $errors = [];

        $document =
            $spec->toArray();

        $seenTargets = [];

        foreach (
            $spec->provenance
            as $index => $entry
        ) {
            /*
             * V1 policy:
             * mỗi target_path chỉ nên có một
             * provenance declaration.
             */
            if (
                isset(
                    $seenTargets[
                        $entry->targetPath
                    ]
                )
            ) {
                $errors[] =
                    new ValidationError(
                        code:
                            'duplicate_provenance_target',

                        path:
                            "provenance."
                            . "{$index}.target_path",

                        message:
                            'A canonical target path '
                            . 'must not have multiple '
                            . 'provenance entries.',

                        actual:
                            $entry->targetPath,
                    );
            }

            $seenTargets[
                $entry->targetPath
            ] = true;

            if (
                !$this->paths->exists(
                    $document,
                    $entry->targetPath
                )
            ) {
                $errors[] =
                    new ValidationError(
                        code:
                            'provenance_target_path_missing',

                        path:
                            "provenance."
                            . "{$index}.target_path",

                        message:
                            'Provenance target_path '
                            . 'does not exist.',

                        expected:
                            'existing canonical path',

                        actual:
                            $entry->targetPath,
                    );
            }

            /*
             * invented:
             *
             * source_aspects phải rỗng.
             */
            if (
                $entry->origin
                === ProvenanceOrigin::INVENTED
                && $entry->sourceAspects !== []
            ) {
                $errors[] =
                    new ValidationError(
                        code:
                            'invented_provenance_has_sources',

                        path:
                            "provenance."
                            . "{$index}.source_aspects",

                        message:
                            'Invented provenance '
                            . 'must have an empty '
                            . 'source_aspects array.',

                        expected:
                            [],

                        actual:
                            $entry->sourceAspects,
                    );
            }

            /*
             * inspired:
             *
             * phải chỉ ra source aspect thật.
             */
            if (
                $entry->origin
                === ProvenanceOrigin::INSPIRED
                && $entry->sourceAspects === []
            ) {
                $errors[] =
                    new ValidationError(
                        code:
                            'inspired_provenance_missing_sources',

                        path:
                            "provenance."
                            . "{$index}.source_aspects",

                        message:
                            'Inspired provenance '
                            . 'must reference at least '
                            . 'one source aspect.',
                    );
            }

            /*
             * Mọi source_aspect khai báo
             * phải có thật trong InspirationBrief.
             */
            foreach (
                $entry->sourceAspects
                as $aspect
            ) {
                if (
                    !$brief->hasSourceAspect(
                        $aspect
                    )
                ) {
                    $errors[] =
                        new ValidationError(
                            code:
                                'unknown_source_aspect',

                            path:
                                "provenance."
                                . "{$index}"
                                . '.source_aspects',

                            message:
                                'Provenance references '
                                . 'a source aspect that '
                                . 'does not exist in '
                                . 'InspirationBrief.',

                            expected:
                                $brief
                                    ->coveredAspects(),

                            actual:
                                $aspect,
                        );
                }
            }
        }

        return $errors;
    }
}
