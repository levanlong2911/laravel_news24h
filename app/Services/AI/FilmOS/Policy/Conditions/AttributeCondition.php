<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Policy\Conditions;

use App\Services\AI\FilmOS\Policy\PolicyCondition;
use App\Services\AI\FilmOS\Policy\PolicyContext;

/**
 * Evaluates a single context attribute using a comparison operator.
 *
 * Examples:
 *   AttributeCondition::eq('content_type', 'breaking_news')
 *   AttributeCondition::lt('budget_remaining_usd', 10.0)
 *   AttributeCondition::lt('reviewer_confidence', 0.7)
 *   AttributeCondition::lt('provider_sla.kling', 0.9)
 */
final class AttributeCondition implements PolicyCondition
{
    private function __construct(
        private readonly string $key,
        private readonly string $operator,
        private readonly mixed  $value,
    ) {}

    // ── Named constructors ────────────────────────────────────────────────────

    public static function eq(string $key, mixed $value): self
    {
        return new self($key, '===', $value);
    }

    public static function neq(string $key, mixed $value): self
    {
        return new self($key, '!==', $value);
    }

    public static function lt(string $key, mixed $value): self
    {
        return new self($key, '<', $value);
    }

    public static function lte(string $key, mixed $value): self
    {
        return new self($key, '<=', $value);
    }

    public static function gt(string $key, mixed $value): self
    {
        return new self($key, '>', $value);
    }

    public static function gte(string $key, mixed $value): self
    {
        return new self($key, '>=', $value);
    }

    /** True if the attribute value is in the given array. */
    public static function in(string $key, array $values): self
    {
        return new self($key, 'in', $values);
    }

    /** True if the attribute key exists in the context. */
    public static function exists(string $key): self
    {
        return new self($key, 'exists', null);
    }

    // ── Evaluation ────────────────────────────────────────────────────────────

    public function evaluate(PolicyContext $context): bool
    {
        if ($this->operator === 'exists') {
            return $context->has($this->key);
        }

        $actual = $context->get($this->key);

        return match ($this->operator) {
            '===' => $actual === $this->value,
            '!==' => $actual !== $this->value,
            '<'   => $actual < $this->value,
            '<='  => $actual <= $this->value,
            '>'   => $actual > $this->value,
            '>='  => $actual >= $this->value,
            'in'  => in_array($actual, $this->value, strict: true),
            default => false,
        };
    }

    public function describe(): string
    {
        $val = is_array($this->value) ? '[' . implode(', ', $this->value) . ']' : (string) $this->value;
        return "context[{$this->key}] {$this->operator} {$val}";
    }
}
