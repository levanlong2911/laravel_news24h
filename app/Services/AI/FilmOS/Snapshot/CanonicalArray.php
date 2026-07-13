<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Snapshot;

/**
 * Recursive canonicalization utility for deterministic hashing.
 *
 * Problem: ksort() is shallow. A nested associative array like
 *
 *   ['metadata' => ['z' => 1, 'a' => 2]]
 *
 * sorted at the top level only still has insertion-order keys inside 'metadata',
 * producing different JSON (and therefore different hashes) between PHP processes
 * that built the array in a different order.
 *
 * Solution: deepSort() recursively ksorts every associative (string-keyed) array.
 * List arrays (sequential int keys starting at 0) are NOT reordered — their order
 * encodes information (e.g. checkpoint sequence, retry history, edge order).
 *
 * Circular reference protection: recursion is bounded by MAX_DEPTH. Exceeding
 * it throws CircularCanonicalizationException — fail loud, never infinite loop.
 * Canonical data should be shallow DTOs; MAX_DEPTH = 32 is generous for any
 * legitimate structure and tight enough to catch reference cycles quickly.
 *
 * Usage:
 *   $canonical = CanonicalArray::deepSort($freeFormArray);
 *   $hash = $serializer->sha256($canonical);
 */
final class CanonicalArray
{
    private const MAX_DEPTH = 32;

    /**
     * Recursively sort all associative array keys.
     * List arrays (0-indexed sequential) are passed through unchanged.
     * Non-array scalars and null are returned as-is.
     *
     * @throws CircularCanonicalizationException if nesting exceeds MAX_DEPTH
     */
    public static function deepSort(mixed $value): mixed
    {
        return static::recurse($value, 0);
    }

    private static function recurse(mixed $value, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw new CircularCanonicalizationException($depth, self::MAX_DEPTH);
        }

        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn($v) => static::recurse($v, $depth + 1), $value);
        }

        ksort($value);
        return array_map(fn($v) => static::recurse($v, $depth + 1), $value);
    }
}
