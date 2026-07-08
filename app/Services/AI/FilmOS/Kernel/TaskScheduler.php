<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Kernel;

/**
 * Priority queue: CRITICAL first, then IMPORTANT, then FILLER.
 * Within the same priority, FIFO order is preserved.
 */
final class TaskScheduler
{
    private const PRIORITY_ORDER = [
        'critical'  => 0,
        'important' => 1,
        'filler'    => 2,
    ];

    /** @var FilmTask[] */
    private array $queue = [];

    public function enqueue(FilmTask $task): void
    {
        $this->queue[] = $task;
        usort($this->queue, function (FilmTask $a, FilmTask $b) {
            return self::PRIORITY_ORDER[$a->priority->value] <=> self::PRIORITY_ORDER[$b->priority->value];
        });
    }

    public function next(): ?FilmTask
    {
        return array_shift($this->queue) ?: null;
    }

    public function isEmpty(): bool
    {
        return empty($this->queue);
    }

    /** @return FilmTask[] */
    public function drainByPriority(ShotPriority $priority): array
    {
        $batch     = [];
        $remaining = [];
        foreach ($this->queue as $task) {
            if ($task->priority === $priority) {
                $batch[] = $task;
            } else {
                $remaining[] = $task;
            }
        }
        $this->queue = $remaining;
        return $batch;
    }
}
