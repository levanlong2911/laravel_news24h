<?php

declare(strict_types=1);

namespace App\Video\Concept\Canonical;

use App\Video\Concept\Canonical\Enums\ProvenanceOrigin;
use InvalidArgumentException;

final class ProvenanceEntry
{
    /**
     * @param  list<string>  $sourceAspects
     */
    public function __construct(
        public readonly string $targetPath,
        public readonly ProvenanceOrigin $origin,
        public readonly array $sourceAspects,
    ) {
        if (trim($targetPath) === '') {
            throw new InvalidArgumentException(
                'provenance.target_path must not be empty.'
            );
        }

        if (
            count($sourceAspects)
            !== count(array_unique($sourceAspects))
        ) {
            throw new InvalidArgumentException(
                'provenance.source_aspects '
                .'must contain unique items.'
            );
        }

        foreach ($sourceAspects as $index => $aspect) {
            if (
                ! is_string($aspect)
                || trim($aspect) === ''
            ) {
                throw new InvalidArgumentException(
                    "provenance.source_aspects[{$index}] "
                    .'must be a non-empty string.'
                );
            }
        }
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $sourceAspects =
            $data['source_aspects'] ?? [];

        if (
            ! is_array($sourceAspects)
            || ! array_is_list($sourceAspects)
        ) {
            throw new InvalidArgumentException(
                'provenance.source_aspects '
                .'must be a list.'
            );
        }

        return new self(
            targetPath: (string) (
                $data['target_path'] ?? ''
            ),

            origin: ProvenanceOrigin::from(
                (string) (
                    $data['origin'] ?? ''
                )
            ),

            sourceAspects: array_values($sourceAspects),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'target_path' => $this->targetPath,

            'origin' => $this->origin->value,

            'source_aspects' => array_values($this->sourceAspects),
        ];
    }
}
