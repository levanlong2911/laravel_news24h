<?php

declare(strict_types=1);

namespace App\Video\Concept\Validation;

use App\Video\Concept\Claude\CanonicalSchemaProvider;
use App\Video\Concept\Exceptions\CanonicalValidationException;
use JsonException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use RuntimeException;

final class CanonicalSchemaValidator
{
    private Validator $validator;

    public function __construct(
        private readonly CanonicalSchemaProvider $schemaProvider,
    ) {
        $this->validator = new Validator();

        /*
         * Production:
         * thu thập nhiều schema errors trong cùng một pass,
         * không stop ngay ở lỗi đầu.
         */
        $this->validator->setMaxErrors(100);
        // $this->validator->setStopAtFirstError(false);
    }

    /**
     * Validate RAW JSON.
     *
     * Quan trọng:
     * dùng raw JSON để không làm mất phân biệt:
     *
     * {}  = JSON object
     * []  = JSON array
     */
    public function validateJson(
        string $rawJson
    ): ValidationResult {
        try {
            $data = json_decode(
                $rawJson,
                flags: JSON_THROW_ON_ERROR,
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

        $schemaJson = $this->loadSchemaJson();

        try {
            $schema = json_decode(
                $schemaJson,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $e) {
            throw new RuntimeException(
                'canonical_design_spec_v1.json '
                . 'is not valid JSON: '
                . $e->getMessage(),
                previous: $e,
            );
        }

        $result = $this->validator->validate(
            $data,
            $schema,
        );

        if ($result->isValid()) {
            return ValidationResult::valid();
        }

        $rootError = $result->error();

        if ($rootError === null) {
            return ValidationResult::invalid([
                new ValidationError(
                    code: 'json_schema',
                    path: '$',
                    message:
                        'Schema validation failed '
                        . 'without an error object.',
                ),
            ]);
        }

        $formatter = new ErrorFormatter();

        /*
         * formatFlat() cho ta toàn bộ nested errors
         * dưới dạng flat list.
         */
        $formatted = $formatter->formatFlat(
            $rootError,
            static function ($error) use ($formatter): array {
                return [
                    'keyword' =>
                        $error->keyword(),

                    'message' =>
                        $formatter
                            ->formatErrorMessage(
                                $error
                            ),

                    'data_path' =>
                        $formatter
                            ->formatErrorKey(
                                $error
                            ),

                    'actual' =>
                        $error->data()->value(),
                ];
            },
        );

        $errors = [];

        foreach ($formatted as $item) {
            if (!is_array($item)) {
                continue;
            }

            $errors[] = new ValidationError(
                code:
                    'json_schema.'
                    . (
                        isset($item['keyword'])
                            ? (string) $item['keyword']
                            : 'unknown'
                    ),

                path:
                    isset($item['data_path'])
                        ? (string) $item['data_path']
                        : '$',

                message:
                    isset($item['message'])
                        ? (string) $item['message']
                        : 'JSON Schema validation failed.',

                actual:
                    $item['actual'] ?? null,
            );
        }

        if ($errors === []) {
            $errors[] = new ValidationError(
                code: 'json_schema',
                path: '$',
                message:
                    'Canonical JSON does not satisfy '
                    . 'canonical_design_spec_v1.json.',
            );
        }

        return ValidationResult::invalid(
            $errors
        );
    }

    public function validateJsonOrFail(
        string $rawJson
    ): void {
        $result = $this->validateJson(
            $rawJson
        );

        if ($result->fails()) {
            throw new CanonicalValidationException(
                errors: $result->errors,
                message:
                    'Canonical JSON Schema validation failed.',
            );
        }
    }


    private function loadSchemaJson(): string
    {
        return $this->schemaProvider
            ->rawJson();
    }
}
