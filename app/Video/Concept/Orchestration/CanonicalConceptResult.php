<?php

declare(strict_types=1);

namespace App\Video\Concept\Orchestration;

use App\Video\Concept\Freeze\FrozenCanonicalConcept;

final class CanonicalConceptResult
{
    public function __construct(
        public readonly FrozenCanonicalConcept $frozen,
        public readonly string $objectType,
        public readonly string $profileKey,
        public readonly string $profileVersion,
    ) {}

    public function revision(): int
    {
        return $this->frozen->revision;
    }

    public function hash(): string
    {
        return $this->frozen->hash;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'object_type' => $this->objectType,

            'profile' => [
                'key' => $this->profileKey,

                'version' => $this->profileVersion,
            ],

            'canonical' => $this->frozen->toArray(),
        ];
    }
}
