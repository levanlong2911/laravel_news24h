<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Selection;

/** A fact that survived into one shot, carrying where it came from. */
final class SelectedFact
{
    /** @param string[] $entityRefs */
    public function __construct(
        public readonly string $factId,
        public readonly array $entityRefs,
        public readonly string $visualHint,
        public readonly Origin $origin,
    ) {}
}
