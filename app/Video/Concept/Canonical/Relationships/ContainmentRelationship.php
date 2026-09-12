<?php

declare(strict_types=1);

namespace App\Video\Concept\Canonical\Relationships;

use App\Video\Concept\Canonical\Enums\RelationshipType;

final class ContainmentRelationship implements Relationship
{
    public function __construct(
        public readonly string $relationshipId,
        public readonly string $containerPath,
        public readonly string $containedPath,
    ) {
        RelationshipGuards::relationshipId(
            $relationshipId
        );

        RelationshipGuards::nonEmpty(
            $containerPath,
            'container_path'
        );

        RelationshipGuards::nonEmpty(
            $containedPath,
            'contained_path'
        );
    }

    public function id(): string
    {
        return $this->relationshipId;
    }

    public function type(): RelationshipType
    {
        return RelationshipType::CONTAINMENT;
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

            'container_path' =>
                $this->containerPath,

            'contained_path' =>
                $this->containedPath,
        ];
    }
}
