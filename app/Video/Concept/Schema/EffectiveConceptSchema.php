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
}
