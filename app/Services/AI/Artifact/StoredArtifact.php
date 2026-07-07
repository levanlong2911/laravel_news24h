<?php

namespace App\Services\AI\Artifact;

/**
 * Result of persisting a provider video artifact to internal storage.
 * The storage_path is the canonical reference; original_url is the provider CDN URL (may expire).
 */
final class StoredArtifact
{
    public function __construct(
        public readonly string $storageDisk,
        public readonly string $storagePath,
        public readonly string $checksum,        // SHA-256 hex digest of the stored file
        public readonly int    $fileSizeBytes,
        public readonly string $originalUrl,     // Provider CDN URL (kept for reference)
    ) {}
}
