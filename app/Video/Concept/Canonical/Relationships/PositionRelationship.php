<?php

declare(strict_types=1);

namespace App\Video\Concept\Canonical\Relationships;

use App\Video\Concept\Canonical\Enums\RelationshipType;

final class PositionRelationship implements Relationship
{
    public function __construct(
        public readonly string $relationshipId,
        public readonly string $subjectPath,
        public readonly string $referencePath,
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
            $referencePath,
            'reference_path'
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
        return RelationshipType::POSITION;
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

            'reference_path' =>
                $this->referencePath,

            'value' =>
                $this->value,
        ];
    }
}
