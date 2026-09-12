<?php

declare(strict_types=1);

namespace App\Video\Concept\Freeze;

use App\Video\Concept\Canonical\CanonicalDesignSpec;
use DateTimeImmutable;
use InvalidArgumentException;

final class FrozenCanonicalConcept
{
    public function __construct(
        public readonly CanonicalDesignSpec $spec,
        public readonly string $canonicalJson,
        public readonly string $hash,
        public readonly int $revision,
        public readonly DateTimeImmutable $frozenAt,
        public readonly FrozenCanonicalConceptMetadata $metadata,
    ) {
        if ($this->canonicalJson === '') {
            throw new InvalidArgumentException(
                'canonicalJson must not be empty.'
            );
        }

        if (
            ! preg_match(
                '/^[a-f0-9]{64}$/',
                $this->hash
            )
        ) {
            throw new InvalidArgumentException(
                'hash must be a SHA-256 hex digest.'
            );
        }

        if ($this->revision < 1) {
            throw new InvalidArgumentException(
                'revision must be >= 1.'
            );
        }
    }

    /**
     * Persistence representation.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'revision' => $this->revision,

            'hash' => $this->hash,

            'frozen_at' => $this->frozenAt
                ->format(DATE_ATOM),

            /*
             * Day la canonical source-of-truth bytes.
             */
            'canonical_json' => $this->canonicalJson,

            'metadata' => $this->metadata
                ->toArray(),
        ];
    }
}
