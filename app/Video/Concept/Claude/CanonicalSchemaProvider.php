<?php

declare(strict_types=1);

namespace App\Video\Concept\Claude;

use JsonException;
use RuntimeException;

final class CanonicalSchemaProvider
{
    /**
     * @var array<string,mixed>|null
     */
    private ?array $cachedSchema = null;

    public function __construct(
        private readonly string $schemaPath,
    ) {}

    /**
     * @return array<string,mixed>
     *
     * @throws JsonException
     */
    public function schema(): array
    {
        if ($this->cachedSchema !== null) {
            return $this->cachedSchema;
        }

        if (! is_file($this->schemaPath)) {
            throw new RuntimeException(
                'Canonical schema not found: '
                .$this->schemaPath
            );
        }

        $json = file_get_contents(
            $this->schemaPath
        );

        if ($json === false) {
            throw new RuntimeException(
                'Unable to read canonical schema: '
                .$this->schemaPath
            );
        }

        $schema = json_decode(
            $json,
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        if (
            ! is_array($schema)
            || array_is_list($schema)
        ) {
            throw new RuntimeException(
                'Canonical schema root '
                .'must be a JSON object.'
            );
        }

        return $this->cachedSchema =
            $schema;
    }

    public function rawJson(): string
    {
        if (! is_file($this->schemaPath)) {
            throw new RuntimeException(
                'Canonical schema not found: '
                .$this->schemaPath
            );
        }

        $json = file_get_contents(
            $this->schemaPath
        );

        if ($json === false) {
            throw new RuntimeException(
                'Unable to read canonical schema: '
                .$this->schemaPath
            );
        }

        return $json;
    }

    public function path(): string
    {
        return $this->schemaPath;
    }
}
