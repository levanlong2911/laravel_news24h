<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Pipeline;

use App\Services\AI\FilmOS\Production\FfmpegPipeline;
use App\Services\AI\FilmOS\Production\ProductionResult;
use App\Services\AI\FilmOS\Production\VideoDownloadManager;

/**
 * Downloads successful shots and merges them into a single output.mp4.
 *
 * VideoDownloadManager is created per-assemble() call so it receives the correct
 * per-production outputDir (only known at runtime, not at service registration).
 *
 * If Transcode/Normalize/Watermark steps are added later, introduce a new
 * RenderAssetAssembler implementation rather than modifying this class.
 */
final class DefaultRenderAssetAssembler implements RenderAssetAssembler
{
    public function __construct(
        private readonly FfmpegPipeline $ffmpeg,
    ) {}

    public function assemble(array $shots, string $outputDir, string $productionId): ProductionResult
    {
        $startTime = microtime(true);

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, recursive: true);
        }

        $downloader   = new VideoDownloadManager($outputDir);
        $successShots = array_filter($shots, fn (RenderedShot $s) => $s->isSuccess());
        $failedShots  = array_filter($shots, fn (RenderedShot $s) => !$s->isSuccess());

        $clips       = [];
        $errors      = [];
        $ffmpegError = null;

        foreach ($failedShots as $shot) {
            $errors[$shot->shotId] = $shot->error ?? $shot->status->value;
        }

        foreach ($successShots as $shot) {
            try {
                $clips[] = $downloader->download($shot->assetUrl, $shot->shotId, $shot->shotOrder);
            } catch (\Throwable $e) {
                $errors[$shot->shotId] = 'Download failed: ' . $e->getMessage();
            }
        }

        $outputPath = null;
        if (!empty($clips)) {
            $workDir    = $outputDir . DIRECTORY_SEPARATOR . 'work';
            $outputPath = $outputDir . DIRECTORY_SEPARATOR . 'output.mp4';
            $result     = $this->ffmpeg->normalizeAndConcat($clips, $outputPath, $workDir);
            if (!$result->success) {
                $ffmpegError = $result->failureReason();
                $outputPath  = null;
            }
        }

        return new ProductionResult(
            success:        $outputPath !== null,
            productionId:   $productionId,
            outputPath:     $outputPath,
            totalShots:     count($shots),
            renderedShots:  count($clips),
            failedShots:    count($errors),
            skippedShots:   0,
            elapsedSeconds: microtime(true) - $startTime,
            renderErrors:   $errors,
            clips:          $clips,
            ffmpegError:    $ffmpegError,
        );
    }
}
