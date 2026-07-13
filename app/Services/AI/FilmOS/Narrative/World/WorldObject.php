<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\World;

use App\Services\AI\FilmOS\Narrative\Shared\AttributeBag;

final class WorldObject
{
    public function __construct(
        public readonly string          $id,          // stable identity, e.g. "hero", "villa_door"
        public readonly WorldObjectType $type,
        public readonly string          $label,       // display name
        public readonly AttributeBag    $attributes,
    ) {}
}
