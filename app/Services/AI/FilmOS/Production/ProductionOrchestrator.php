<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Production;

use App\Services\AI\FilmOS\Intent\DirectorIntent;
use App\Services\AI\FilmOS\Kernel\Plugins\RenderPlugin;
use App\Services\AI\Provider\Dto\RenderVideoRequest;
use App\Services\AI\Provider\RenderVideoProvider;

/**
 * Orchestrates the render → download → merge pipeline for a production run.
 *
 * Separation of concerns:
 *   ProductionOrchestrator — coordinates async lifecycle (submit, poll, download, merge)
 *   RenderVideoProvider    — single task: submit / status / artifact / nextPollDelay
 *   VideoDownloadManager   — download one clip: retry, cache, checksum
 *   FfmpegPipeline         — FFmpeg operations: normalize, concat
 *
 * Async strategy (PHP synchronous process):
 *   1. Submit ALL tasks upfront — provider starts rendering in parallel on its side.
 *   2. Poll pending tasks in rounds with a shared sleep between rounds.
 *      Each round: check every pending task; move terminal ones to downloaded/failed.
 *   3. Download immediately when a task completes (while others still render).
 *   4. Merge after all tasks reach terminal state.
 *
 * This gives approximate parallelism without threads:
 *   20 shots × 5-min render → total wall time ≈ 5 min (not 100 min).
 */
final class ProductionOrchestrator
{
    private const POLL_ROUND_SLEEP_SECONDS = 15;
    private const MAX_POLL_ROUNDS          = 80;   // 80 × 15s = 20 min hard timeout

    public function __construct(
        private readonly RenderVideoProvider  $provider,
        private readonly VideoDownloadManager $downloader,
        private readonly FfmpegPipeline       $ffmpeg,
    ) {}

    /**
     * Run the full render → download → merge pipeline.
     *
     * @param  array<string, DirectorIntent>  $intents       shotId → DirectorIntent (in production order)
     * @param  string                         $productionId
     * @param  string                         $outputDir     root output directory for this production
     * @param  callable|null                  $onProgress    fn(string $message) — called with status updates
     */
    public function produce(
        array    $intents,
        string   $productionId,
        string   $outputDir,
        ?callable $onProgress = null,
    ): ProductionResult {
        $startTime = microtime(true);
        $log       = $onProgress ?? fn(string $_) => null;

        // ── Phase 1: Submit all tasks ─────────────────────────────────────────
        /** @var RenderTask[] $tasks  providerTaskId → RenderTask */
        $tasks = [];

        $ordinal = 0;
        foreach ($intents as $shotId => $intent) {
            $filmOsTaskId = "render_{$shotId}";
            $prompt       = RenderPlugin::buildPromptFromIntent($intent);

            $request = new RenderVideoRequest(
                prompt:          $prompt,
                negativePrompt:  'text overlay, logo, watermark, blurry, low quality, distorted',
                durationSeconds: 5,
                aspectRatio:     '9:16',
            );

            try {
                $submit = $this->provider->submit($request);
                $task   = new RenderTask(
                    filmOsTaskId:   $filmOsTaskId,
                    providerTaskId: $submit->taskId,
                    shotId:         $shotId,
                    ordinal:        $ordinal,
                    prompt:         $prompt,
                );
                $tasks[$submit->taskId] = $task;
                $log("  → submitted [{$filmOsTaskId}] → provider_task={$submit->taskId}");
            } catch (\Throwable $e) {
                $log("  ✗ submit failed [{$filmOsTaskId}]: {$e->getMessage()}");
                // Track as a failed task so it shows up in the result
                $failedTask = new RenderTask(
                    filmOsTaskId:   $filmOsTaskId,
                    providerTaskId: 'submit_failed_' . $ordinal,
                    shotId:         $shotId,
                    ordinal:        $ordinal,
                    prompt:         $prompt,
                );
                $failedTask->errorMessage = $e->getMessage();
                $failedTask->status       = \App\Services\AI\Provider\RenderVideoStatus::FAILED;
                $tasks['submit_failed_' . $ordinal] = $failedTask;
            }

            $ordinal++;
        }

        // ── Phase 2: Round-robin polling ──────────────────────────────────────
        $pending = array_filter($tasks, fn(RenderTask $t) => !$t->isTerminal());
        $rounds  = 0;

        while (!empty($pending) && $rounds < self::MAX_POLL_ROUNDS) {
            sleep(self::POLL_ROUND_SLEEP_SECONDS);
            $rounds++;

            foreach ($pending as $providerTaskId => $task) {
                try {
                    $status = $this->provider->status($task->providerTaskId);
                    $task->applyStatus($status);

                    if ($task->isTerminal()) {
                        unset($pending[$providerTaskId]);

                        if ($task->isSuccess() && $task->videoUrl !== null) {
                            $log("  ✓ render done [{$task->shotId}] — downloading…");

                            try {
                                $clip       = $this->downloader->download($task->videoUrl, $task->shotId, $task->ordinal);
                                $task->clip = $clip;
                                $log("    saved {$clip->localPath} ({$this->humanBytes($clip->sizeBytes)})");
                            } catch (\Throwable $e) {
                                $task->errorMessage = "Download failed: {$e->getMessage()}";
                                $task->status       = \App\Services\AI\Provider\RenderVideoStatus::FAILED;
                                $log("    ✗ download failed [{$task->shotId}]: {$e->getMessage()}");
                            }
                        } elseif ($task->isSuccess()) {
                            // COMPLETED but provider returned no video URL — treat as failed
                            $task->errorMessage = 'Provider returned COMPLETED but no video URL';
                            $task->status       = \App\Services\AI\Provider\RenderVideoStatus::FAILED;
                            $log("  ✗ no video URL [{$task->shotId}]: COMPLETED with null videoUrl");
                        } elseif (!$task->isSuccess()) {
                            $log("  ✗ render failed [{$task->shotId}]: {$task->errorMessage}");
                        }
                    } else {
                        $log("  … [{$task->shotId}] {$task->status->value} (poll #{$task->pollAttempts})");
                    }
                } catch (\Throwable $e) {
                    $log("  ✗ poll error [{$task->shotId}]: {$e->getMessage()}");
                }
            }
        }

        if (!empty($pending)) {
            $log("  ⚠ timeout — " . count($pending) . " tasks still pending after "
                . self::MAX_POLL_ROUNDS . " rounds");
            foreach ($pending as $task) {
                $task->errorMessage = 'Timed out after ' . (self::MAX_POLL_ROUNDS * self::POLL_ROUND_SLEEP_SECONDS) . 's';
                $task->status       = \App\Services\AI\Provider\RenderVideoStatus::TIMEOUT;
            }
        }

        // ── Phase 3: FFmpeg normalize + concat ────────────────────────────────
        $clips = array_filter(
            array_map(fn(RenderTask $t) => $t->clip, $tasks),
            fn(?DownloadedClip $c) => $c !== null,
        );

        $errors        = [];
        $outputPath    = null;
        $ffmpegError   = null;

        foreach ($tasks as $task) {
            if ($task->errorMessage !== null && !$task->isSuccess()) {
                $errors[$task->shotId] = $task->errorMessage;
            }
        }

        if (!empty($clips)) {
            $workDir    = $outputDir . DIRECTORY_SEPARATOR . 'work';
            $outputPath = $outputDir . DIRECTORY_SEPARATOR . 'output.mp4';

            $log("  FFmpeg: normalizing " . count($clips) . " clips…");
            $mergeResult = $this->ffmpeg->normalizeAndConcat(array_values($clips), $outputPath, $workDir);

            if ($mergeResult->success) {
                $log("  ✓ merged → {$outputPath}");
            } else {
                $ffmpegError = $mergeResult->failureReason();
                $outputPath  = null;
                $log("  ✗ FFmpeg failed: {$ffmpegError}");
            }
        }

        // ── Result ────────────────────────────────────────────────────────────
        $totalTasks    = count($tasks);
        $renderedShots = count(array_filter($tasks, fn(RenderTask $t) => $t->isSuccess()));
        $failedShots   = count(array_filter($tasks, fn(RenderTask $t) => !$t->isSuccess() && $t->isTerminal()));

        return new ProductionResult(
            success:        $outputPath !== null,
            productionId:   $productionId,
            outputPath:     $outputPath,
            totalShots:     $totalTasks,
            renderedShots:  $renderedShots,
            failedShots:    $failedShots,
            skippedShots:   $totalTasks - $renderedShots - $failedShots,
            elapsedSeconds: microtime(true) - $startTime,
            renderErrors:   $errors,
            clips:          array_values($clips),
            ffmpegError:    $ffmpegError,
        );
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes >= 1_048_576) {
            return round($bytes / 1_048_576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }
}
