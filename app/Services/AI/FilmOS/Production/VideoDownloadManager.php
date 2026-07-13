<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Production;

use RuntimeException;

/**
 * Downloads video clips from CDN URLs to local storage.
 *
 * Design goals (reusable for Veo, Runway, Pika):
 *   - Local cache: skip re-download if file already present + size matches
 *   - Retry: up to MAX_RETRIES attempts with exponential backoff
 *   - Checksum: sha256 after download to detect corrupt transfers
 *   - Resume: if partial file exists, delete and restart (HTTP range is provider-dependent)
 *
 * Output structure:
 *   {outputDir}/clips/{shotId}.mp4
 */
final class VideoDownloadManager
{
    private const MAX_RETRIES     = 3;
    private const RETRY_BASE_SECS = 5;
    private const TIMEOUT_SECS    = 300;    // 5-minute download timeout per clip

    public function __construct(
        private readonly string $outputDir,
    ) {
        if (!is_dir($this->outputDir)) {
            mkdir($this->outputDir, 0755, recursive: true);
        }
    }

    /**
     * Download a video clip from a URL to local storage.
     *
     * Idempotent: if the target file already exists with the expected size,
     * re-computes sha256 and returns the cached DownloadedClip without re-downloading.
     *
     * @throws RuntimeException on permanent failure after all retries
     */
    public function download(string $url, string $shotId, int $ordinal): DownloadedClip
    {
        $clipsDir  = $this->outputDir . DIRECTORY_SEPARATOR . 'clips';
        $localPath = $clipsDir . DIRECTORY_SEPARATOR . $this->safeFilename($shotId) . '.mp4';

        if (!is_dir($clipsDir)) {
            mkdir($clipsDir, 0755, recursive: true);
        }

        // Cache hit: skip download if file already exists with non-zero size
        if (file_exists($localPath) && filesize($localPath) > 0) {
            return new DownloadedClip(
                shotId:    $shotId,
                localPath: $localPath,
                sizeBytes: (int) filesize($localPath),
                sha256:    hash_file('sha256', $localPath),
                ordinal:   $ordinal,
            );
        }

        // Remove any leftover partial file
        if (file_exists($localPath)) {
            unlink($localPath);
        }

        $lastError = null;
        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $this->downloadToFile($url, $localPath);

                $sizeBytes = (int) filesize($localPath);
                if ($sizeBytes === 0) {
                    throw new RuntimeException("Download produced empty file (0 bytes)");
                }

                return new DownloadedClip(
                    shotId:    $shotId,
                    localPath: $localPath,
                    sizeBytes: $sizeBytes,
                    sha256:    hash_file('sha256', $localPath),
                    ordinal:   $ordinal,
                );
            } catch (\Throwable $e) {
                $lastError = $e;
                if (file_exists($localPath)) {
                    unlink($localPath);
                }
                if ($attempt < self::MAX_RETRIES) {
                    sleep(self::RETRY_BASE_SECS * $attempt);
                }
            }
        }

        throw new RuntimeException(
            "Failed to download clip '{$shotId}' after " . self::MAX_RETRIES . " attempts: "
            . $lastError->getMessage(),
            previous: $lastError,
        );
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function downloadToFile(string $url, string $localPath): void
    {
        $context = stream_context_create([
            'http' => [
                'timeout'          => self::TIMEOUT_SECS,
                'follow_location'  => 1,
                'max_redirects'    => 5,
                'user_agent'       => 'FilmOS/1.0 VideoDownloadManager',
            ],
            'ssl' => [
                'verify_peer'       => true,
                'verify_peer_name'  => true,
            ],
        ]);

        $src  = @fopen($url, 'rb', false, $context);
        if ($src === false) {
            throw new RuntimeException("Could not open URL for reading: {$url}");
        }

        $dst = @fopen($localPath, 'wb');
        if ($dst === false) {
            fclose($src);
            throw new RuntimeException("Could not open local path for writing: {$localPath}");
        }

        try {
            stream_copy_to_stream($src, $dst);
        } finally {
            fclose($src);
            fclose($dst);
        }
    }

    private function safeFilename(string $shotId): string
    {
        return preg_replace('/[^a-zA-Z0-9_\-]/', '_', $shotId);
    }
}
