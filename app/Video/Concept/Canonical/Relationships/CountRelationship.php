<?php

declare(strict_types=1);

namespace App\Video\Concept\Canonical\Relationships;

use App\Video\Concept\Canonical\Enums\RelationshipType;
use InvalidArgumentException;

final class CountRelationship implements Relationship
{
    public function __construct(
        public readonly string $relationshipId,
        public readonly string $subjectPath,
        public readonly int $value,
    ) {
        RelationshipGuards::relationshipId(
            $relationshipId
        );

        RelationshipGuards::nonEmpty(
            $subjectPath,
            'subject_path'
        );

        if ($value < 0) {
            throw new InvalidArgumentException(
                'CountRelationship.value '
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
        return RelationshipType::COUNT;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->relationshipId,

            'type' =>
                $this->type()->value,

            'subject_path' =>
                $this->subjectPath,

            'value' =>
                $this->value,
        ];
    }
}
