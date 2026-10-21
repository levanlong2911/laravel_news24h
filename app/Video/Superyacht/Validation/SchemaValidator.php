<?php

namespace App\Video\Superyacht\Validation;

use InvalidArgumentException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Validates pipeline documents against the 8 JSON Schemas (draft 2020-12).
 *
 * All schemas are registered in one resolver, so cross-schema references such
 * as CanonicalDesign -> "$ref": "BuildState" resolve correctly.
 *
 * Pass raw JSON strings whenever you can: decoding JSON into PHP arrays turns an
 * empty object {} into an empty list [], which then fails "type": "object".
 */
final class SchemaValidator
{
    private Validator $validator;

    /** @param array<int,string> $schemaNames */
    public function __construct(
        private readonly string $schemaDir,
        private readonly string $baseUri,
        private readonly array $schemaNames,
        int $maxErrors = 50,
    ) {
        $this->validator = new Validator();
        $this->validator->setMaxErrors($maxErrors);
        // opis/json-schema 2.3.0 (ban du an dang khoa) chua co ham nay; setMaxErrors da du de gom nhieu loi.
        if (method_exists($this->validator, 'setStopAtFirstError')) {
            $this->validator->setStopAtFirstError(false);
        }

        foreach ($this->schemaNames as $name) {
            $file = rtrim($this->schemaDir, '/') . "/{$name}.json";
            if (! is_file($file)) {
                throw new InvalidArgumentException("Schema file not found: {$file}");
            }
            $this->validator->resolver()->registerFile($this->uri($name), $file);
        }
    }

    public static function fromConfig(array $config): self
    {
        return new self($config['schema_dir'], $config['schema_base_uri'], $config['schemas']);
    }

    /**
     * @param string|array|object $data raw JSON string (preferred), decoded object or array
     */
    public function validate(string $schemaName, string|array|object $data): ValidationReport
    {
        if (! in_array($schemaName, $this->schemaNames, true)) {
            throw new InvalidArgumentException("Unknown schema: {$schemaName}");
        }

        $value = $this->toJsonValue($data);
        $result = $this->validator->validate($value, $this->uri($schemaName));

        if ($result->isValid()) {
            return new ValidationReport();
        }

        $violations = [];
        $formatted = (new ErrorFormatter())->format($result->error(), true);
        foreach ($formatted as $path => $messages) {
            foreach ((array) $messages as $message) {
                $violations[] = new Violation("SCHEMA-{$schemaName}", (string) $message, (string) $path);
            }
        }

        return new ValidationReport($violations);
    }

    public function uri(string $schemaName): string
    {
        return rtrim($this->baseUri, '/') . '/' . $schemaName;
    }

    private function toJsonValue(string|array|object $data): mixed
    {
        if (is_string($data)) {
            try {
                return json_decode($data, false, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new InvalidArgumentException('Invalid JSON: ' . $e->getMessage(), 0, $e);
            }
        }

        // Arrays: re-encode so associative arrays become objects.
        return json_decode(json_encode($data, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), false, 512, JSON_THROW_ON_ERROR);
    }
}
