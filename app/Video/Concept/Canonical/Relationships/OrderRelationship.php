<?php

declare(strict_types=1);

namespace App\Video\Concept\Canonical\Relationships;

use App\Video\Concept\Canonical\Enums\RelationshipType;

final class OrderRelationship implements Relationship
{
    /**
     * @param list<string> $items
     */
    public function __construct(
        public readonly string $relationshipId,
        public readonly array $items,
        public readonly ?string $direction = null,
    ) {
        RelationshipGuards::relationshipId(
            $relationshipId
        );

        RelationshipGuards::nonEmptyStrings(
            $items,
            'items',
            2
        );
    }

    public function id(): string
    {
        return $this->relationshipId;
    }

    public function type(): RelationshipType
    {
        return RelationshipType::ORDER;
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

            'items' =>
                array_values($this->items),
        ];

        if ($this->direction !== null) {
            $out['direction'] =
                $this->direction;
        }

        return $out;
    }
}
