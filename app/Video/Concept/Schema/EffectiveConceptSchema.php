<?php

declare(strict_types=1);

namespace App\Video\Concept\Schema;

final class EffectiveConceptSchema
{
    /**
     * @param  array<string,mixed>  $schema
     */
    public function __construct(
        public readonly string $coreVersion,
        public readonly string $profileKey,
        public readonly string $profileVersion,
        public readonly array $schema,
    ) {}

    public function identifier(): string
    {
        return sprintf(
            'canonical@%s+%s@%s',
            $this->coreVersion,
            $this->profileKey,
            $this->profileVersion,
        );
    }

    public function hash(): string
    {
        return hash(
            'sha256',
            json_encode(
                $this->sortKeysRecursively($this->schema),
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR
            )
        );
    }

    /**
     * @return array{
     *     canonical_schema:string,
     *     profile_key:string,
     *     profile_version:string,
     *     effective_schema_hash:string
     * }
     */
    public function metadata(): array
    {
        return [
            'canonical_schema' => $this->coreVersion,
            'profile_key' => $this->profileKey,
            'profile_version' => $this->profileVersion,
            'effective_schema_hash' => $this->hash(),
        ];
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private function sortKeysRecursively(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortKeysRecursively($item);
            }
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
