<?php

namespace App\Services\AI\AFOS\Passes\Pipeline;

/**
 * CanonicalSerializer — deterministic JSON encoding for stable hashing.
 *
 * Standard json_encode() is fragile for hashing:
 *   - array key order varies between PHP versions and across serialization paths
 *   - float precision depends on serialize_precision ini (varies by environment)
 *   - unicode handling differs between PHP versions
 *
 * This class produces a hash-stable canonical form:
 *   - associative array keys sorted recursively
 *   - floats rounded to 10 decimal places (eliminates FP noise)
 *   - JSON_UNESCAPED_UNICODE (UTF-8 bytes, no \uXXXX escaping)
 *   - JSON_UNESCAPED_SLASHES (clean output)
 *   - lists (0-indexed arrays) preserved in order
 *
 * LLVM equivalent: FileChecksum / BitcodeHash canonical encoding.
 */
final class CanonicalSerializer
{
    private function __construct() {}

    /** SHA-1 of the canonical JSON encoding. */
    public static function hash(mixed $value): string
    {
        return sha1(self::encode($value));
    }

    /** Canonical JSON string — deterministic across environments and runs. */
    public static function encode(mixed $value): string
    {
        return json_encode(
            self::normalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    private static function normalize(mixed $value): mixed
    {
        if (is_float($value)) {
            // Stable float: 10 decimal places eliminates FP noise while preserving precision
            return round($value, 10);
        }

        if (is_array($value)) {
            $normalized = array_map(self::normalize(...), $value);

            // Sort associative arrays by key; preserve list order
            if (!array_is_list($value)) {
                ksort($normalized);
            }

            return $normalized;
        }

        return $value;
    }
}
