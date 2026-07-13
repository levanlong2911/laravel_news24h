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

    /**
     * Serialize $data and return its SHA-256 hex digest.
     *
     * Single source of truth for all canonical hashing — no caller should call
     * hash('sha256', ...) + serialize() separately. Changing the digest algorithm
     * (e.g. SHA-512, BLAKE3) requires updating only this method.
     *
     * @param array<string, mixed> $data
     */
    public function sha256(array $data): string;
}
