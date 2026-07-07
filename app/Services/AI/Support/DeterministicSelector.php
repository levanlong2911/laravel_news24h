<?php

namespace App\Services\AI\Support;

/**
 * Selects a deterministic item from a non-empty array using a string seed.
 *
 * Used across the entire AI stack: atmosphere variants, camera suffixes,
 * transition styles, lighting choices, scheduling tie-breaking, etc.
 *
 * Algorithm: abs(crc32(seed)) % count(items) — stable across PHP versions and platforms.
 * Same (seed, items) pair always returns the same item.
 */
final class DeterministicSelector
{
    /**
     * @template T
     * @param  non-empty-array<T> $items
     * @return T
     *
     * @throws \LogicException if $items is empty
     */
    public static function pick(string $seed, array $items): mixed
    {
        if ($items === []) {
            throw new \LogicException('DeterministicSelector::pick() requires a non-empty array.');
        }

        return $items[abs(crc32($seed)) % count($items)];
    }
}
