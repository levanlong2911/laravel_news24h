<?php

declare(strict_types=1);

namespace App\Video\Concept\Canonical\Relationships;

use App\Video\Concept\Canonical\Enums\RelationshipType;

interface Relationship
{
    public function id(): string;

    public function type(): RelationshipType;

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array;
}
