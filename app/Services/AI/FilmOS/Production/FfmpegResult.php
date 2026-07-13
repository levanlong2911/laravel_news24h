<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Production;

final class FfmpegResult
{
    public function __construct(
        public readonly bool    $success,
        public readonly string  $outputPath,
        public readonly int     $exitCode,
        public readonly string  $stdout,
        public readonly string  $stderr,
        public readonly ?float  $durationSeconds = null,
    ) {}

    public function failureReason(): string
    {
        $tail = implode("\n", array_slice(explode("\n", $this->stderr), -10));
        return "exit={$this->exitCode}: {$tail}";
    }
}
