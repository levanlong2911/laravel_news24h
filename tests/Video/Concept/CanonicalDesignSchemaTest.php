<?php

namespace Tests\Video\Concept;

use App\Services\Video\Concept\CanonicalDesignSchema;
use RuntimeException;
use Tests\TestCase;

class CanonicalDesignSchemaTest extends TestCase
{
    public function test_it_loads_the_shipped_schema(): void
    {
        $schema = (new CanonicalDesignSchema)->load();

        $this->assertSame('object', $schema['type'] ?? null);
        $this->assertFalse($schema['additionalProperties'] ?? true);
    }

    public function test_it_rejects_an_empty_schema_file(): void
    {
        $path = $this->schemaFile('');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Canonical design schema is empty: {$path}");

        (new CanonicalDesignSchema($path))->load();
    }

    public function test_it_rejects_a_non_object_schema_root(): void
    {
        $path = $this->schemaFile('"not an object"');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Canonical design schema root must be an object: {$path}");

        (new CanonicalDesignSchema($path))->load();
    }

    public function test_it_rejects_a_missing_schema_file(): void
    {
        $path = storage_path('framework/testing/missing-canonical-schema.json');

        @unlink($path);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Canonical design schema not found: {$path}");

        (new CanonicalDesignSchema($path))->load();
    }

    private function schemaFile(string $contents): string
    {
        $dir = storage_path('framework/testing');

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $path = $dir.'/canonical-schema-'.bin2hex(random_bytes(4)).'.json';
        file_put_contents($path, $contents);

        return $path;
    }
}
