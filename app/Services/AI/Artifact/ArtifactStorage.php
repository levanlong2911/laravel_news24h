<?php

namespace App\Services\AI\Artifact;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Downloads a provider video artifact and persists it to a Laravel filesystem disk.
 *
 * Storage path: renders/{pipelineRunId}/{taskId}.mp4
 *
 * Design note (content-addressed deduplication):
 *   After computing the SHA-256 checksum, a future upgrade can move the file to
 *   renders/{checksum[0..1]}/{checksum}.mp4 and maintain a taskId → checksum mapping,
 *   de-duplicating identical renders without changing this interface.
 *
 * The $sink closure is the HTTP-to-disk download step. The default streams the response
 * directly to a temp file (no full body in RAM). Inject a replacement in tests.
 *
 * @phpstan-type SinkFn callable(string $url, string $destPath): void
 */
final class ArtifactStorage implements ArtifactStorageInterface
{
    /** @var callable(string $url, string $destPath): void */
    private readonly mixed $sink;

    public function __construct(
        private readonly string $disk            = 'local',
        private readonly int    $downloadTimeout = 300,
        ?callable               $sink            = null,
    ) {
        $timeout    = $this->downloadTimeout;
        $this->sink = $sink ?? static function (string $url, string $destPath) use ($timeout): void {
            $response = Http::timeout($timeout)->sink($destPath)->get($url);
            if (! $response->successful()) {
                throw new \RuntimeException(
                    "Artifact download failed: HTTP {$response->status()} from '{$url}'"
                );
            }
        };
    }

    public static function fromConfig(): self
    {
        return new self(
            disk:            (string) config('ai.artifact.disk', 'local'),
            downloadTimeout: (int)    config('ai.artifact.download_timeout', 300),
        );
    }

    public function store(string $taskId, string $cdnUrl, string $pipelineRunId): StoredArtifact
    {
        $storagePath = "renders/{$pipelineRunId}/{$taskId}.mp4";
        $tempPath    = rtrim(sys_get_temp_dir(), '/\\') . '/' . uniqid($taskId . '_', true) . '.mp4';

        try {
            ($this->sink)($cdnUrl, $tempPath);

            $checksum = hash_file('sha256', $tempPath);
            $size     = (int) filesize($tempPath);

            $stream = fopen($tempPath, 'rb');
            try {
                // writeStream() may return false on some Flysystem adapters without throwing.
                $ok = Storage::disk($this->disk)->writeStream($storagePath, $stream);
                if ($ok === false) {
                    throw new \RuntimeException(
                        "Failed to write artifact to disk '{$this->disk}' at '{$storagePath}'"
                    );
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            return new StoredArtifact(
                storageDisk:   $this->disk,
                storagePath:   $storagePath,
                checksum:      $checksum,
                fileSizeBytes: $size,
                originalUrl:   $cdnUrl,
            );
        } finally {
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }
    }
}
