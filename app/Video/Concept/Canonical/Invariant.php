<?php

declare(strict_types=1);

namespace App\Video\Concept\Canonical;

use App\Video\Concept\Canonical\Enums\ConstraintPrimitive;
use App\Video\Concept\Canonical\Enums\InvariantSeverity;
use InvalidArgumentException;

final class Invariant
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $sourcePath,
        public readonly ConstraintPrimitive $constraintType,
        public readonly InvariantSeverity $severity,
        public readonly bool $visualVerification,
    ) {
        if (! preg_match('/^I[0-9]{3}$/', $id)) {
            throw new InvalidArgumentException(
                'Invariant id must match '
                ."^I[0-9]{3}$: {$id}"
            );
        }

        if (
            trim($name) === ''
            || trim($sourcePath) === ''
        ) {
            throw new InvalidArgumentException(
                'Invariant name and source_path '
                .'must not be empty.'
            );
        }
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),

            name: (string) ($data['name'] ?? ''),

            sourcePath: (string) ($data['source_path'] ?? ''),

            constraintType: ConstraintPrimitive::from(
                (string) (
                    $data['constraint_type'] ?? ''
                )
            ),

            severity: InvariantSeverity::from(
                (string) (
                    $data['severity'] ?? ''
                )
            ),

            visualVerification: (bool) (
                $data['visual_verification'] ?? false
            ),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,

            'name' => $this->name,

            'source_path' => $this->sourcePath,

            'constraint_type' => $this->constraintType->value,

            'severity' => $this->severity->value,

            'visual_verification' => $this->visualVerification,
        ];
    }
}
