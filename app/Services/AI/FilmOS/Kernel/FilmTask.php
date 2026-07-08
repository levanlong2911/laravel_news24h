<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Kernel;

final class FilmTask
{
    public function __construct(
        public readonly string       $id,
        public readonly TaskType     $type,
        public readonly ShotPriority $priority,
        public readonly mixed        $payload,
        public readonly int          $deadlineMs = 15000,
        public readonly array        $dependsOn  = [],
    ) {}
}
