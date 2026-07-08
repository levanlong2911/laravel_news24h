<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Kernel;

/** Phase 1 stub — capacity check always passes. */
final class MemoryManager
{
    public function canFit(FilmTask $task): bool
    {
        return true;
    }
}
