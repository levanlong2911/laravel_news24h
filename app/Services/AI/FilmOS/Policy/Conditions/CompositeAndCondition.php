<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Policy\Conditions;

use App\Services\AI\FilmOS\Policy\PolicyCondition;
use App\Services\AI\FilmOS\Policy\PolicyContext;

final class CompositeAndCondition implements PolicyCondition
{
    /** @param PolicyCondition[] $conditions */
    public function __construct(private readonly array $conditions) {}

    public function evaluate(PolicyContext $context): bool
    {
        foreach ($this->conditions as $condition) {
            if (!$condition->evaluate($context)) {
                return false;
            }
        }
        return true;
    }

    public function describe(): string
    {
        return '(' . implode(' AND ', array_map(
            static fn(PolicyCondition $c) => $c->describe(),
            $this->conditions,
        )) . ')';
    }
}
