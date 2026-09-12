<?php

declare(strict_types=1);

namespace App\Video\Concept\Canonical\Relationships;

use App\Video\Concept\Canonical\Enums\RelationshipType;

final class ContinuityRelationship implements Relationship
{
    public function __construct(
        public readonly string $relationshipId,
        public readonly string $subjectPath,
        public readonly string $value,
        public readonly ?string $startPath = null,
        public readonly ?string $endPath = null,
    ) {
        RelationshipGuards::relationshipId(
            $relationshipId
        );

        RelationshipGuards::nonEmpty(
            $subjectPath,
            'subject_path'
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
        return RelationshipType::CONTINUITY;
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

            'value' =>
                $this->value,
        ];

        if ($this->startPath !== null) {
            $out['start_path'] =
                $this->startPath;
        }

        if ($this->endPath !== null) {
            $out['end_path'] =
                $this->endPath;
        }

        return $out;
    }
}
