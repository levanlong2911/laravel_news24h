<?php

declare(strict_types=1);

namespace App\Video\Concept\Validation;

use App\Video\Concept\Schema\EffectiveConceptSchema;
use JsonException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

final class EffectiveSchemaValidator
{
    public function validate(
        string $rawJson,
        EffectiveConceptSchema $effective
    ): ValidationResult {
        try {
            $data =
                json_decode(
                    $rawJson,
                    associative: false,
                    depth: 512,
                    flags: JSON_THROW_ON_ERROR
                );
        } catch (JsonException $e) {
            return ValidationResult::invalid([
                new ValidationError(
                    code: 'invalid_json',

                    path: '$',

                    message: $e->getMessage(),
                ),
            ]);
        }

        /*
         * Convert schema array back to object-mode
         * de Opis thay JSON object/list chinh xac.
         */
        $schemaJson =
            json_encode(
                $effective->schema,
                JSON_THROW_ON_ERROR
            );

        $schema =
            json_decode(
                $schemaJson,
                associative: false,
                depth: 512,
                flags: JSON_THROW_ON_ERROR
            );

        $validator =
            new Validator;

        $validator->setMaxErrors(100);

        $result =
            $validator->validate(
                $data,
                $schema
            );

        if ($result->isValid()) {
            return ValidationResult::valid();
        }

        $root =
            $result->error();

        if ($root === null) {
            return ValidationResult::invalid([
                new ValidationError(
                    code: 'effective_schema',

                    path: '$',

                    message: 'Effective schema '
                        .'validation failed.',
                ),
            ]);
        }

        $formatter =
            new ErrorFormatter;

        $flat =
            $formatter->formatFlat(
                $root,
                static function (
                    $error
                ) use (
                    $formatter
                ): array {
                    return [
                        'keyword' => $error->keyword(),

                        'message' => $formatter
                            ->formatErrorMessage(
                                $error
                            ),

                        'path' => $formatter
                            ->formatErrorKey(
                                $error
                            ),

                        'actual' => $error
                            ->data()
                            ->value(),
                    ];
                }
            );

        $errors = [];

        foreach ($flat as $item) {
            if (! is_array($item)) {
                continue;
            }

            $errors[] =
                new ValidationError(
                    code: 'effective_schema.'
                        .(
                            $item['keyword']
                            ?? 'unknown'
                        ),

                    path: (string) (
                        $item['path']
                        ?? '$'
                    ),

                    message: (string) (
                        $item['message']
                        ?? 'Effective schema validation failed.'
                    ),

                    actual: $item['actual']
                        ?? null,
                );
        }

        return ValidationResult::invalid(
            $errors
        );
    }
}
