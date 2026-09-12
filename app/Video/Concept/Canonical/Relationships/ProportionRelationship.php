<?php

declare(strict_types=1);

namespace App\Video\Concept\Canonical\Relationships;

use App\Video\Concept\Canonical\Enums\RelationshipType;
use InvalidArgumentException;

final class ProportionRelationship implements Relationship
{
    public function __construct(
        public readonly string $relationshipId,
        public readonly string $subjectPath,
        public readonly string $metric,
        public readonly float $value,
        public readonly ?float $tolerance = null,
    ) {
        RelationshipGuards::relationshipId(
            $relationshipId
        );

        RelationshipGuards::nonEmpty(
            $subjectPath,
            'subject_path'
        );

        RelationshipGuards::nonEmpty(
            $metric,
            'metric'
        );

        if (
            $tolerance !== null
            && $tolerance < 0
        ) {
            throw new InvalidArgumentException(
                'ProportionRelationship.tolerance '
                . 'must be >= 0.'
            );
        }
    }

    public function id(): string
    {
        return $this->relationshipId;
    }

    public function type(): RelationshipType
    {
        return RelationshipType::PROPORTION;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $out = [
            'id' =>
                $this->relationshipId,

            'type' =>
                $this->type()->value,

            'subject_path' =>
                $this->subjectPath,

            'metric' =>
                $this->metric,

            'value' =>
                $this->value,
        ];

        if ($this->tolerance !== null) {
            $out['tolerance'] =
                $this->tolerance;
        }

        return $out;
    }
}
