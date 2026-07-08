<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Kernel\Plugins;

use App\Services\AI\FilmOS\Kernel\FilmTask;
use App\Services\AI\FilmOS\Kernel\TaskResult;
use App\Services\AI\FilmOS\Kernel\TaskType;

interface KernelPlugin
{
    /** @return TaskType[] */
    public function taskTypes(): array;

    public function execute(FilmTask $task): TaskResult;
}
