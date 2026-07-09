<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Snapshot;

/**
 * Converts structured data to a canonical byte string for SHA-256 hashing.
 *
 * GraphHash depends on this interface, not on json_encode(), so that
 * Replay Servers in other runtimes (Rust, Go, Node) can use the same
 * serialization format (MessagePack, CBOR, etc.) and produce identical hashes.
 *
 * Default implementation: JsonHashSerializer (JSON with sorted keys, no escaping).
 */
interface HashSerializer
{
    /**
     * Produce a deterministic byte string from $data.
     * The output must be identical for equal inputs on any platform.
     *
     * @param array<string, mixed> $data
     */
    public function serialize(array $data): string;
}
