<?php

declare(strict_types=1);

namespace App\Video\Concept\Canonical\Relationships;

use App\Video\Concept\Canonical\Enums\RelationshipType;

final class SymmetryRelationship implements Relationship
{
    public function __construct(
        public readonly string $relationshipId,
        public readonly string $subjectPath,
        public readonly string $axis,
        public readonly string $value,
    ) {
        RelationshipGuards::relationshipId(
            $relationshipId
        );

        RelationshipGuards::nonEmpty(
            $subjectPath,
            'subject_path'
        );

        RelationshipGuards::nonEmpty(
            $axis,
            'axis'
        );

        RelationshipGuards::nonEmpty(
            $value,
            'value'
        );
    }

    public function id(): string
    {
        return $this->relationshipId;
    }

    public function type(): RelationshipType
    {
        return RelationshipType::SYMMETRY;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'id' =>
                $this->relationshipId,

            'type' =>
                $this->type()->value,

            'subject_path' =>
                $this->subjectPath,

            'axis' =>
                $this->axis,

            'value' =>
                $this->value,
        ];
    }
}
