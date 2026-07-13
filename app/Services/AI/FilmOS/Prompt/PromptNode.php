<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompt;

final class PromptNode
{
    public function __construct(
        public readonly string $id,
        public readonly string $namespace,
        public readonly mixed  $value,
        public readonly float  $weight     = 1.0,
        public readonly array  $attributes = [],
    ) {}

    public function withWeight(float $weight): self
    {
        return new self($this->id, $this->namespace, $this->value, $weight, $this->attributes);
    }

    public function withValue(mixed $value): self
    {
        return new self($this->id, $this->namespace, $value, $this->weight, $this->attributes);
    }
}
