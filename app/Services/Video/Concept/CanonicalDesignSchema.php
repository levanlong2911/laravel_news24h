<?php

namespace App\Services\Video\Concept;

use RuntimeException;

final class CanonicalDesignSchema
{
    private const PATH = 'contracts/renderplan/v1.0/ai/schemas/canonical_design_spec_v1.json';

    /** @var array<string, mixed>|null */
    private ?array $schema = null;

    public function __construct(
        private readonly ?string $path = null,
    ) {}

    /** @return array<string, mixed> */
    public function load(): array
    {
        if ($this->schema !== null) {
            return $this->schema;
        }

        $path = $this->path ?? base_path(self::PATH);

        if (! is_file($path)) {
            throw new RuntimeException("Canonical design schema not found: {$path}");
        }

        $json = (string) file_get_contents($path);

        // Rong thi json_decode chi noi "Syntax error", va nguoi doc log se di tim
        // dau phay thua trong mot file khong co ky tu nao.
        if (trim($json) === '') {
            throw new RuntimeException("Canonical design schema is empty: {$path}");
        }

        $schema = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($schema) || array_is_list($schema)) {
            throw new RuntimeException("Canonical design schema root must be an object: {$path}");
        }

        return $this->schema = $schema;
    }
}
