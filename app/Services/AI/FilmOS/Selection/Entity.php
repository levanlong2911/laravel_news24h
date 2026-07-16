<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Selection;

/** Something the article says exists. Identity only — nothing about where it appears. */
final class Entity
{
    /** @param array<string, string> $attributes */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $label,
        public readonly array $attributes = [],
    ) {}
}
