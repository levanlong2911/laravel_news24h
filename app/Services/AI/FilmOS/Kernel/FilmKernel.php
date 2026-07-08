<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Kernel;

use App\Services\AI\FilmOS\Kernel\Plugins\KernelPlugin;

/**
 * Task-agnostic OS kernel. Knows FilmTask/Priority/Dependencies only.
 * Domain knowledge lives in KernelPlugin implementations (Invariant from ADR-014).
 */
final class FilmKernel
{
    /** @var array<string, KernelPlugin> task type value → plugin */
    private array $plugins = [];

    private readonly TaskScheduler $scheduler;
    private readonly MemoryManager $memory;

    public function __construct(TaskScheduler $scheduler, MemoryManager $memory)
    {
        $this->scheduler = $scheduler;
        $this->memory    = $memory;
    }

    public function registerPlugin(KernelPlugin $plugin): void
    {
        foreach ($plugin->taskTypes() as $type) {
            $this->plugins[$type->value] = $plugin;
        }
    }

    public function submit(FilmTask $task): void
    {
        $this->scheduler->enqueue($task);
    }

    /** Run all queued tasks and return results keyed by task ID. */
    public function runAll(): array
    {
        $results = [];

        while (!$this->scheduler->isEmpty()) {
            $task = $this->scheduler->next();

            if (!$this->memory->canFit($task)) {
                // Phase 1: always passes — this branch is unreachable
                $results[$task->id] = new TaskResult(
                    taskId:     $task->id,
                    success:    false,
                    output:     null,
                    durationMs: 0,
                    error:      'Out of kernel memory',
                );
                continue;
            }

            $plugin = $this->plugins[$task->type->value] ?? null;

            if ($plugin === null) {
                $results[$task->id] = new TaskResult(
                    taskId:     $task->id,
                    success:    false,
                    output:     null,
                    durationMs: 0,
                    error:      "No plugin registered for task type: {$task->type->value}",
                );
                continue;
            }

            $results[$task->id] = $plugin->execute($task);
        }

        return $results;
    }
}
