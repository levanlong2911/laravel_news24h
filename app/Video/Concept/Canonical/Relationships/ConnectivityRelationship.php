<?php

declare(strict_types=1);

namespace App\Video\Concept\Canonical\Relationships;

use App\Video\Concept\Canonical\Enums\RelationshipType;

final class ConnectivityRelationship implements Relationship
{
    /**
     * @param list<string> $members
     */
    public function __construct(
        public readonly string $relationshipId,
        public readonly array $members,
        public readonly string $value,
    ) {
        RelationshipGuards::relationshipId(
            $relationshipId
        );

        RelationshipGuards::nonEmptyStrings(
            $members,
            'members',
            2
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
        return RelationshipType::CONNECTIVITY;
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

            'members' =>
                array_values($this->members),

            'value' =>
                $this->value,
        ];
    }
}
