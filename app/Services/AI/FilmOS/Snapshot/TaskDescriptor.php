<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Snapshot;

use App\Services\AI\FilmOS\Kernel\FilmTask;

/**
 * Immutable, scheduler-agnostic description of a task for snapshot hashing.
 *
 * Decouples the Snapshot layer from Kernel\FilmTask so that the Scheduler
 * implementation can be replaced (e.g. swap FilmTask for a queue-native type)
 * without affecting determinism verification.
 *
 * Only structural fields are kept — no payload, no mutable runtime state.
 */
final class TaskDescriptor
{
    public function __construct(
        public readonly string  $id,
        public readonly string  $type,        // TaskType enum value
        public readonly string  $priority,    // ShotPriority enum value
        public readonly array   $dependsOn,   // string[] — task IDs
        public readonly ?float  $deadlineMs,
    ) {}

    public static function fromFilmTask(FilmTask $task): self
    {
        return new self(
            id:          $task->id,
            type:        $task->type->value,
            priority:    $task->priority->value,
            dependsOn:   $task->dependsOn,
            deadlineMs:  (float) $task->deadlineMs,
        );
    }

    /** @return array<string, string|float|null|string[]> */
    public function toArray(): array
    {
        $deps = $this->dependsOn;
        sort($deps);
        return [
            'id'         => $this->id,
            'type'       => $this->type,
            'priority'   => $this->priority,
            'dependsOn'  => $deps,
            'deadlineMs' => $this->deadlineMs,
        ];
    }
}
