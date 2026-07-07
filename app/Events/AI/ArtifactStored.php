<?php

namespace App\Events\AI;

/**
 * Fired after StoreArtifactJob downloads the provider video and persists it
 * to internal storage. The artifact is addressable by storage_disk + storage_path.
 */
final class ArtifactStored implements LoggableRenderEvent
{
    public const VERSION = 1;

    public function __construct(
        public readonly string $pipelineRunId,
        public readonly string $taskId,
        public readonly string $storageDisk,
        public readonly string $storagePath,
        public readonly string $checksum,       // SHA-256 hex
        public readonly int    $fileSizeBytes,
        public readonly string $storedAt,       // ISO-8601
    ) {}

    public function toLog(): array
    {
        return [
            'event_version'    => self::VERSION,
            'pipeline_run_id'  => $this->pipelineRunId,
            'provider_task_id' => $this->taskId,
            'storage_disk'     => $this->storageDisk,
            'storage_path'     => $this->storagePath,
            'checksum_sha256'  => $this->checksum,
            'file_size_bytes'  => $this->fileSizeBytes,
            'stored_at'        => $this->storedAt,
        ];
    }
}
