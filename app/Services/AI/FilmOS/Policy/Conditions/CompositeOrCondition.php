<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Policy\Conditions;

use App\Services\AI\FilmOS\Policy\PolicyCondition;
use App\Services\AI\FilmOS\Policy\PolicyContext;

final class CompositeOrCondition implements PolicyCondition
{
    /** @param PolicyCondition[] $conditions */
    public function __construct(private readonly array $conditions) {}

    public function evaluate(PolicyContext $context): bool
    {
        foreach ($this->conditions as $condition) {
            if ($condition->evaluate($context)) {
                return true;
            }
        }
        return false;
    }

    public function describe(): string
    {
        return '(' . implode(' OR ', array_map(
            static fn(PolicyCondition $c) => $c->describe(),
            $this->conditions,
        )) . ')';
    }
}
