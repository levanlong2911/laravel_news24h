<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Production;

use RuntimeException;

/**
 * FFmpeg-based video processing pipeline.
 *
 * Designed to be extended later with: BGM overlay, voice-over, subtitle burn-in,
 * color grading, transitions. Each operation is a discrete step so callers can
 * compose only what they need.
 *
 * For Phase E.5 vertical slice, two operations are implemented:
 *
 *   normalize() — re-encode a single clip to a canonical format
 *     (resolution=1920×1080, fps=24, codec=H.264/AAC)
 *     Ensures all clips are compatible before concat.
 *
 *   concat() — stitch normalized clips into one output file (stream-copy, fast)
 *
 * Usage:
 *   $ffmpeg = new FfmpegPipeline();
 *   $normalized = array_map(fn($c, $i) => $ffmpeg->normalize($c, "{$workDir}/n{$i}.mp4"), $clips);
 *   $result = $ffmpeg->concat(array_column($normalized, 'outputPath'), $outputPath);
 */
final class FfmpegPipeline
{
    private const DEFAULT_WIDTH   = 1080;
    private const DEFAULT_HEIGHT  = 1920;
    private const DEFAULT_FPS     = 24;
    private const DEFAULT_CRF     = 23;
    private const DEFAULT_PRESET  = 'fast';
    private const TIMEOUT_SECONDS = 600;   // 10 min per operation

    public function __construct(
        private readonly string $ffmpegBin = 'ffmpeg',
    ) {}

    /**
     * Re-encode a clip to canonical 1080p/24fps/H.264.
     * Adds silent stereo audio track if the input has no audio stream.
     *
     * @throws RuntimeException if ffmpeg fails or binary not found
     */
    public function normalize(string $inputPath, string $outputPath): FfmpegResult
    {
        $w   = self::DEFAULT_WIDTH;
        $h   = self::DEFAULT_HEIGHT;
        $fps = self::DEFAULT_FPS;
        $crf = self::DEFAULT_CRF;
        $pre = self::DEFAULT_PRESET;

        // scale to 1080p, pad black bars, lock fps — all in filter_complex so
        // it unambiguously applies to the output, not to a second input stream.
        $filterComplex = "[0:v]scale={$w}:{$h}:force_original_aspect_ratio=decrease,"
            . "pad={$w}:{$h}:(ow-iw)/2:(oh-ih)/2:black,"
            . "fps={$fps}[v]";

        // Two-input command: video source + silent lavfi audio source.
        // All output options come AFTER both -i flags to avoid FFmpeg
        // misinterpreting them as input-side options for the lavfi source.
        $cmd = [
            $this->ffmpegBin, '-y',
            '-i', $inputPath,
            '-f', 'lavfi', '-i', 'aevalsrc=0:c=stereo:s=44100:d=600',
            '-filter_complex', $filterComplex,
            '-map', '[v]',
            '-map', '1:a:0',
            '-c:v', 'libx264', '-crf', (string) $crf, '-preset', $pre,
            '-c:a', 'aac', '-ar', '44100', '-ac', '2', '-b:a', '128k',
            '-shortest',
            $outputPath,
        ];

        return $this->run($cmd);
    }

    /**
     * Concatenate pre-normalized clips using stream-copy (fast, lossless join).
     * All clips MUST share the same codec/fps/resolution — run normalize() first.
     *
     * @param  string[]  $normalizedPaths  absolute paths in sequence order
     */
    public function concat(array $normalizedPaths, string $outputPath): FfmpegResult
    {
        if (empty($normalizedPaths)) {
            throw new \InvalidArgumentException("concat() requires at least one clip path");
        }

        $listPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'filmos_concat_' . uniqid() . '.txt';
        $lines    = array_map(fn($p) => "file '" . str_replace("'", "'\\''", $p) . "'", $normalizedPaths);
        file_put_contents($listPath, implode("\n", $lines));

        try {
            $cmd = [
                $this->ffmpegBin, '-y',
                '-f', 'concat', '-safe', '0',
                '-i', $listPath,
                '-c', 'copy',
                $outputPath,
            ];

            return $this->run($cmd);
        } finally {
            @unlink($listPath);
        }
    }

    /**
     * Full pipeline: normalize each clip, then concat into final output.
     * Normalized intermediates are stored in $workDir and deleted on success.
     *
     * @param  DownloadedClip[]  $clips   in playback order (sorted by ordinal)
     * @param  string            $workDir scratch directory for normalized intermediates
     */
    public function normalizeAndConcat(
        array  $clips,
        string $outputPath,
        string $workDir,
    ): FfmpegResult {
        if (!is_dir($workDir)) {
            mkdir($workDir, 0755, recursive: true);
        }

        usort($clips, fn(DownloadedClip $a, DownloadedClip $b) => $a->ordinal <=> $b->ordinal);

        $normalizedPaths = [];
        foreach ($clips as $i => $clip) {
            $normPath = $workDir . DIRECTORY_SEPARATOR . "norm_{$i}.mp4";
            $result   = $this->normalize($clip->localPath, $normPath);
            if (!$result->success) {
                return $result;
            }
            $normalizedPaths[] = $normPath;
        }

        $concatResult = $this->concat($normalizedPaths, $outputPath);

        // Clean up intermediates only on success (keep on failure for debugging)
        if ($concatResult->success) {
            foreach ($normalizedPaths as $path) {
                @unlink($path);
            }
        }

        return $concatResult;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * @param  string[]  $cmd
     */
    private function run(array $cmd): FfmpegResult
    {
        // C.7.1 Windows compatibility: stderr → temp file to avoid pipe-buffer deadlock.
        // FFmpeg writes all progress to stderr; reading stdout first blocks forever when
        // the stderr buffer fills. Writing stderr directly to a file sidesteps the issue.
        //
        // TODO C.9: Replace with non-blocking multiplexed pipes using a proc_get_status()
        //           polling loop + stream_set_blocking(false) to support real-time progress
        //           callbacks without temp-file I/O.
        $tmpErr = tempnam(sys_get_temp_dir(), 'filmos_ffm_');

        $descriptor = [
            0 => ['pipe', 'r'],          // stdin
            1 => ['pipe', 'w'],          // stdout  (FFmpeg writes nothing here)
            2 => ['file', $tmpErr, 'w'], // stderr  → file (avoids deadlock)
        ];

        $process = proc_open($cmd, $descriptor, $pipes);
        if ($process === false) {
            @unlink($tmpErr);
            throw new RuntimeException(
                "proc_open failed — is '{$this->ffmpegBin}' installed and in PATH?"
            );
        }

        fclose($pipes[0]);
        fclose($pipes[1]); // stdout unused — close immediately

        try {
            $exitCode = proc_close($process);
            $stderr   = (string) @file_get_contents($tmpErr);
        } finally {
            @unlink($tmpErr);
        }

        $success = $exitCode === 0;

        // Extract duration from stderr ("Duration: HH:MM:SS.xx")
        $duration = null;
        if (preg_match('/Duration:\s*(\d+):(\d+):([\d.]+)/', $stderr, $m)) {
            $duration = (float) $m[1] * 3600 + (float) $m[2] * 60 + (float) $m[3];
        }

        return new FfmpegResult(
            success:         $success,
            outputPath:      end($cmd),   // last arg is always the output file
            exitCode:        $exitCode,
            stdout:          '',
            stderr:          $stderr,
            durationSeconds: $duration,
        );
    }
}
