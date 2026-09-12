<?php

declare(strict_types=1);

namespace App\Video\Profiles;

use InvalidArgumentException;

final class CategoryCreativeProfile
{
    /**
     * @param  list<string>  $inspectionAspects
     */
    public function __construct(
        public readonly string $key,
        public readonly string $version,
        public readonly string $schemaPath,
        public readonly array $inspectionAspects,
    ) {
        $this->guard();
    }

    private function guard(): void
    {
        if (trim($this->key) === '') {
            throw new InvalidArgumentException(
                'CategoryCreativeProfile.key must not be empty.'
            );
        }

        if (trim($this->version) === '') {
            throw new InvalidArgumentException(
                'CategoryCreativeProfile.version must not be empty.'
            );
        }

        if (trim($this->schemaPath) === '') {
            throw new InvalidArgumentException(
                'CategoryCreativeProfile.schemaPath must not be empty.'
            );
        }

        if ($this->inspectionAspects === []) {
            throw new InvalidArgumentException(
                'CategoryCreativeProfile.inspectionAspects must not be empty.'
            );
        }

        foreach ($this->inspectionAspects as $index => $aspect) {
            if (
                ! is_string($aspect)
                || trim($aspect) === ''
            ) {
                throw new InvalidArgumentException(
                    "inspectionAspects[{$index}] must be a non-empty string."
                );
            }
        }

        if (
            count($this->inspectionAspects)
            !== count(array_unique($this->inspectionAspects))
        ) {
            throw new InvalidArgumentException(
                'inspectionAspects must be unique.'
            );
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,

            'version' => $this->version,

            'inspection_aspects' => array_values(
                $this->inspectionAspects
            ),
        ];
    }

    public function identifier(): string
    {
        return sprintf(
            '%s@%s',
            $this->key,
            $this->version
        );
    }
}
