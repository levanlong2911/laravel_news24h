<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Snapshot;

/**
 * Default HashSerializer — UTF-8 JSON with no Unicode escaping.
 *
 * Key properties for determinism:
 *  - JSON_UNESCAPED_UNICODE: multi-byte characters are not escaped
 *  - JSON_THROW_ON_ERROR: never silently produces null for bad input
 *  - Key order: callers must ksort() their arrays before passing here;
 *    this serializer does NOT impose key ordering itself, preserving
 *    the caller's canonical ordering intent.
 */
final class JsonHashSerializer implements HashSerializer
{
    public function serialize(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
