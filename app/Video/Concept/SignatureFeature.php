<?php

namespace App\Video\Concept;

final class SignatureFeature
{
    /** @param list<Viewpoint> $visibleFrom */
    public function __construct(
        public readonly string $description,
        public readonly array $visibleFrom,
    ) {}

    public function isVisibleFrom(Viewpoint $viewpoint): bool
    {
        return in_array($viewpoint, $this->visibleFrom, true);
    }

    /** @return array{description: string, visible_from: list<string>} */
    public function toArray(): array
    {
        return [
            'description' => $this->description,
            'visible_from' => array_map(fn (Viewpoint $v) => $v->value, $this->visibleFrom),
        ];
    }
}
