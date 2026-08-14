<?php

namespace App\Video\Inspiration;

final class ExcludedContext
{
    public function __construct(
        public readonly string $type,
        public readonly string $value,
    ) {}

    /** @return array{type: string, value: string} */
    public function toArray(): array
    {
        return ['type' => $this->type, 'value' => $this->value];
    }
}
