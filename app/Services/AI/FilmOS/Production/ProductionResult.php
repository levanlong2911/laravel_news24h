<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Production;

final class ProductionResult
{
    /**
     * @param  array<string, string>  $renderErrors  shotId → errorMessage
     * @param  DownloadedClip[]       $clips
     */
    public function __construct(
        public readonly bool    $success,
        public readonly string  $productionId,
        public readonly ?string $outputPath,
        public readonly int     $totalShots,
        public readonly int     $renderedShots,
        public readonly int     $failedShots,
        public readonly int     $skippedShots,
        public readonly float   $elapsedSeconds,
        public readonly array   $renderErrors = [],
        public readonly array   $clips = [],
        public readonly ?string $ffmpegError = null,
    ) {}

    public function summary(): string
    {
        $parts = [
            "{$this->renderedShots}/{$this->totalShots} shots rendered",
            sprintf('%.1fs', $this->elapsedSeconds),
        ];
        if ($this->failedShots > 0) {
            $parts[] = "{$this->failedShots} failed";
        }
        if ($this->outputPath !== null) {
            $parts[] = "→ " . basename($this->outputPath);
        }
        return implode(', ', $parts);
    }
}
