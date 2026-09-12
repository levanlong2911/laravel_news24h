<?php

declare(strict_types=1);

namespace App\Video\Concept\Canonical\Relationships;

use App\Video\Concept\Canonical\Enums\RelationshipType;
use InvalidArgumentException;

final class GroupingRelationship implements Relationship
{
    public function __construct(
        public readonly string $relationshipId,
        public readonly string $subjectPath,
        public readonly int $groupCount,
        public readonly ?string $groupedIntoPath = null,
        public readonly ?string $meaning = null,
    ) {
        RelationshipGuards::relationshipId(
            $relationshipId
        );

        RelationshipGuards::nonEmpty(
            $subjectPath,
            'subject_path'
        );

        if ($groupCount < 1) {
            throw new InvalidArgumentException(
                'GroupingRelationship.group_count '
                . 'must be >= 1.'
            );
        }
    }

    public function id(): string
    {
        return $this->relationshipId;
    }

    public function type(): RelationshipType
    {
        return RelationshipType::GROUPING;
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

            'group_count' =>
                $this->groupCount,
        ];

        if (
            $this->groupedIntoPath !== null
        ) {
            $out['grouped_into_path'] =
                $this->groupedIntoPath;
        }

        if ($this->meaning !== null) {
            $out['meaning'] =
                $this->meaning;
        }

        return $out;
    }
}
