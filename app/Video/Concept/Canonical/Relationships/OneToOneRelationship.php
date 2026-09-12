<?php

declare(strict_types=1);

namespace App\Video\Concept\Canonical\Relationships;

use App\Video\Concept\Canonical\Enums\RelationshipType;

final class OneToOneRelationship implements Relationship
{
    public function __construct(
        public readonly string $relationshipId,
        public readonly string $sourcePath,
        public readonly string $targetPath,
        public readonly ?string $meaning = null,
    ) {
        RelationshipGuards::relationshipId(
            $relationshipId
        );

        RelationshipGuards::nonEmpty(
            $sourcePath,
            'source_path'
        );

        RelationshipGuards::nonEmpty(
            $targetPath,
            'target_path'
        );
    }

    public function id(): string
    {
        return $this->relationshipId;
    }

    public function type(): RelationshipType
    {
        return RelationshipType::ONE_TO_ONE;
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

            'source_path' =>
                $this->sourcePath,

            'target_path' =>
                $this->targetPath,
        ];

        if ($this->meaning !== null) {
            $out['meaning'] =
                $this->meaning;
        }

        return $out;
    }
}
