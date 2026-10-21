<?php

namespace App\Video\Superyacht\Support;

use InvalidArgumentException;

/**
 * Canonical JSON serialisation and SHA-256 hashing used for every hash in the
 * pipeline (content hashes, compiled shot hashes, request hashes, bundle hashes).
 *
 * Rules: object keys sorted recursively, lists keep their order, no escaped
 * slashes or unicode, floats keep a zero fraction (1.0 stays 1.0), and an empty
 * object {} is written as [] - so a document hashes the same whether it was
 * decoded with json_decode($json, true) or json_decode($json, false).
 */
final class CanonicalJson
{
    public const PREFIX = 'sha256:';

    public static function encode(mixed $data): string
    {
        $json = json_encode(
            self::normalise($data),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );

        return $json;
    }

    /**
     * Hash of a document, optionally ignoring some top-level keys
     * (e.g. the document's own "hash" field).
     *
     * @param array<int,string> $excludeKeys
     */
    public static function hash(mixed $data, array $excludeKeys = []): string
    {
        if ($excludeKeys !== []) {
            if (is_object($data)) {
                $data = clone $data;
                foreach ($excludeKeys as $key) {
                    unset($data->{$key});
                }
            } elseif (is_array($data)) {
                foreach ($excludeKeys as $key) {
                    unset($data[$key]);
                }
            }
        }

        return self::PREFIX . hash('sha256', self::encode($data));
    }

    public static function hashString(string $raw): string
    {
        return self::PREFIX . hash('sha256', $raw);
    }

    public static function hashFile(string $path): string
    {
        $digest = hash_file('sha256', $path);
        if ($digest === false) {
            throw new InvalidArgumentException("Cannot read file for hashing: {$path}");
        }

        return self::PREFIX . $digest;
    }

    /**
     * Bundle hash of a lock record: SHA-256 of the sorted component hashes, one per line.
     *
     * @param array<int,string> $componentHashes
     */
    public static function bundleHash(array $componentHashes): string
    {
        $hashes = array_values($componentHashes);
        sort($hashes, SORT_STRING);

        return self::PREFIX . hash('sha256', implode("\n", $hashes));
    }

    public static function isValidHash(?string $hash): bool
    {
        return is_string($hash) && preg_match('/^sha256:[a-f0-9]{64}$/', $hash) === 1;
    }

    private static function normalise(mixed $data): mixed
    {
        if (is_object($data)) {
            $data = get_object_vars($data);
            if ($data === []) {
                return [];
            }
            ksort($data, SORT_STRING);
            $out = new \stdClass();
            foreach ($data as $key => $value) {
                $out->{$key} = self::normalise($value);
            }

            return $out;
        }

        if (is_array($data)) {
            if (array_is_list($data)) {
                return array_map([self::class, 'normalise'], $data);
            }
            ksort($data, SORT_STRING);
            $out = new \stdClass();
            foreach ($data as $key => $value) {
                $out->{$key} = self::normalise($value);
            }

            return $out;
        }

        return $data;
    }
}
