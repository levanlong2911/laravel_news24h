<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Kernel;

final class TaskResult
{
    public function __construct(
        public readonly string $taskId,
        public readonly bool   $success,
        public readonly mixed  $output,
        public readonly int    $durationMs,
        public readonly string $dagNodeId = '',
        public readonly string $error     = '',
    ) {}
}
