<?php

namespace App\Services\AI;

use App\Services\AI\Contracts\SchemaValidator;
use App\Services\AI\Contracts\ValidationResult;

/**
 * Lightweight JSON Schema validator covering the subset used by this project.
 *
 * Supported keywords: required, type, enum, pattern, properties, items,
 *   minItems, maxItems, minimum, maximum, exclusiveMinimum, exclusiveMaximum,
 *   additionalProperties (false only).
 *
 * NOT supported: $ref, oneOf, anyOf, allOf, if/then, patternProperties.
 * Those are not used in contracts/v1/*.schema.json as of Phase A.
 *
 * Swap the implementation (not the interface) if full JSON Schema support
 * becomes necessary later.
 */
final class PlanningValidator implements SchemaValidator
{
    private readonly string $contractsPath;

    public function __construct(?string $contractsPath = null)
    {
        $this->contractsPath = $contractsPath ?? base_path('contracts/v1');
    }

    public function validate(array $data, string $schemaName): ValidationResult
    {
        $schemaFile = $this->contractsPath . DIRECTORY_SEPARATOR . $schemaName . '.schema.json';

        if (!file_exists($schemaFile)) {
            return ValidationResult::fail([[
                'field'    => '$schema',
                'expected' => 'file exists',
                'actual'   => "not found: {$schemaFile}",
            ]]);
        }

        $raw = file_get_contents($schemaFile);
        $schema = json_decode($raw, true);

        if (!is_array($schema)) {
            return ValidationResult::fail([[
                'field'    => '$schema',
                'expected' => 'valid JSON object',
                'actual'   => 'parse error: ' . json_last_error_msg(),
            ]]);
        }

        $errors = [];
        $this->validateNode($data, $schema, '$', $errors);

        return $errors === [] ? ValidationResult::pass() : ValidationResult::fail($errors);
    }

    // -------------------------------------------------------------------------
    // Core recursive node validator
    // -------------------------------------------------------------------------

    private function validateNode(mixed $data, array $schema, string $path, array &$errors): void
    {
        // Skip meta-keywords that we don't evaluate
        if (isset($schema['type'])) {
            $this->checkType($data, $schema['type'], $path, $errors);
        }

        if (isset($schema['enum'])) {
            $this->checkEnum($data, $schema['enum'], $path, $errors);
        }

        if (isset($schema['pattern']) && is_string($data)) {
            $this->checkPattern($data, $schema['pattern'], $path, $errors);
        }

        if (is_array($data) && !array_is_list($data)) {
            $this->validateObject($data, $schema, $path, $errors);
        }

        if (is_array($data) && array_is_list($data)) {
            $this->validateArray($data, $schema, $path, $errors);
        }

        if (is_int($data) || is_float($data)) {
            $this->checkNumericBounds($data, $schema, $path, $errors);
        }
    }

    // -------------------------------------------------------------------------
    // Type check
    // -------------------------------------------------------------------------

    private function checkType(mixed $data, string|array $type, string $path, array &$errors): void
    {
        $types = is_array($type) ? $type : [$type];

        foreach ($types as $t) {
            if ($this->matchesType($data, $t)) {
                return;
            }
        }

        $expected = is_array($type) ? implode('|', $type) : $type;
        $errors[] = [
            'field'    => $path,
            'expected' => "type:{$expected}",
            'actual'   => $this->phpType($data),
        ];
    }

    private function matchesType(mixed $data, string $type): bool
    {
        return match ($type) {
            'string'  => is_string($data),
            'integer' => is_int($data),
            'number'  => is_int($data) || is_float($data),
            'boolean' => is_bool($data),
            'null'    => $data === null,
            // PHP cannot distinguish {} from [] for empty decode, so accept []
            'object'  => is_array($data) && ($data === [] || !array_is_list($data)),
            'array'   => is_array($data) && array_is_list($data),
            default   => false,
        };
    }

    private function phpType(mixed $data): string
    {
        if ($data === null) return 'null';
        if (is_bool($data)) return 'boolean';
        if (is_int($data)) return 'integer';
        if (is_float($data)) return 'float';
        if (is_string($data)) return 'string';
        if (is_array($data)) return array_is_list($data) ? 'array' : 'object';
        return gettype($data);
    }

    // -------------------------------------------------------------------------
    // Enum check
    // -------------------------------------------------------------------------

    private function checkEnum(mixed $data, array $allowed, string $path, array &$errors): void
    {
        if (!in_array($data, $allowed, true)) {
            $errors[] = [
                'field'    => $path,
                'expected' => 'enum:' . implode('|', $allowed),
                'actual'   => $data,
            ];
        }
    }

    // -------------------------------------------------------------------------
    // Object validation
    // -------------------------------------------------------------------------

    private function validateObject(array $data, array $schema, string $path, array &$errors): void
    {
        // required
        foreach ($schema['required'] ?? [] as $field) {
            if (!array_key_exists($field, $data)) {
                $errors[] = [
                    'field'    => "{$path}.{$field}",
                    'expected' => 'required',
                    'actual'   => 'missing',
                ];
            }
        }

        // additionalProperties: false
        if (($schema['additionalProperties'] ?? null) === false && isset($schema['properties'])) {
            $allowed = array_keys($schema['properties']);
            foreach (array_keys($data) as $key) {
                if (!in_array($key, $allowed, true)) {
                    $errors[] = [
                        'field'    => "{$path}.{$key}",
                        'expected' => 'not allowed (additionalProperties=false)',
                        'actual'   => 'present',
                    ];
                }
            }
        }

        // properties — recurse into defined properties that are present
        foreach ($schema['properties'] ?? [] as $key => $propSchema) {
            if (array_key_exists($key, $data)) {
                $this->validateNode($data[$key], $propSchema, "{$path}.{$key}", $errors);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Array validation
    // -------------------------------------------------------------------------

    private function validateArray(array $data, array $schema, string $path, array &$errors): void
    {
        $count = count($data);

        if (isset($schema['minItems']) && $count < $schema['minItems']) {
            $errors[] = [
                'field'    => $path,
                'expected' => "minItems:{$schema['minItems']}",
                'actual'   => $count,
            ];
        }

        if (isset($schema['maxItems']) && $count > $schema['maxItems']) {
            $errors[] = [
                'field'    => $path,
                'expected' => "maxItems:{$schema['maxItems']}",
                'actual'   => $count,
            ];
        }

        if (isset($schema['items'])) {
            foreach ($data as $i => $item) {
                $this->validateNode($item, $schema['items'], "{$path}[{$i}]", $errors);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Pattern check (ECMA regex, applied only to strings)
    // -------------------------------------------------------------------------

    private function checkPattern(string $data, string $pattern, string $path, array &$errors): void
    {
        if (!preg_match('/' . str_replace('/', '\\/', $pattern) . '/', $data)) {
            $errors[] = [
                'field'    => $path,
                'expected' => "pattern:{$pattern}",
                'actual'   => $data,
            ];
        }
    }

    // -------------------------------------------------------------------------
    // Numeric bounds (draft-07: exclusiveMinimum/Maximum are numbers, not booleans)
    // -------------------------------------------------------------------------

    private function checkNumericBounds(int|float $data, array $schema, string $path, array &$errors): void
    {
        if (isset($schema['minimum']) && $data < $schema['minimum']) {
            $errors[] = ['field' => $path, 'expected' => "minimum:{$schema['minimum']}", 'actual' => $data];
        }

        if (isset($schema['maximum']) && $data > $schema['maximum']) {
            $errors[] = ['field' => $path, 'expected' => "maximum:{$schema['maximum']}", 'actual' => $data];
        }

        if (isset($schema['exclusiveMinimum']) && $data <= $schema['exclusiveMinimum']) {
            $errors[] = ['field' => $path, 'expected' => "exclusiveMinimum:{$schema['exclusiveMinimum']}", 'actual' => $data];
        }

        if (isset($schema['exclusiveMaximum']) && $data >= $schema['exclusiveMaximum']) {
            $errors[] = ['field' => $path, 'expected' => "exclusiveMaximum:{$schema['exclusiveMaximum']}", 'actual' => $data];
        }
    }
}
