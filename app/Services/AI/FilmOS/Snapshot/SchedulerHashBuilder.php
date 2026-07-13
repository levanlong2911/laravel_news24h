<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Snapshot;

/**
 * Produces a canonical hash of the scheduler task topology (or null if no tasks).
 *
 * Hashes TaskDescriptor[] — not FilmTask[] — so the Scheduler implementation
 * can be swapped (FilmTask → ExecutionTask → queue-native type) without
 * affecting snapshot comparison.
 *
 * Fields hashed per task (from TaskDescriptor::toArray()):
 *   id, type, priority, dependsOn (sorted), deadlineMs
 *
 * Tasks are sorted by id before hashing so submission order does not matter —
 * only the task graph topology is captured.
 *
 * HashSerializer is injected so encoding flags match across all hash builders.
 */
final class SchedulerHashBuilder
{
    public function __construct(
        private readonly HashSerializer $serializer = new JsonHashSerializer(),
    ) {}

    /**
     * @param  TaskDescriptor[] $descriptors
     */
    public function build(array $descriptors): ?string
    {
        if (empty($descriptors)) {
            return null;
        }

        usort($descriptors, fn(TaskDescriptor $a, TaskDescriptor $b) => strcmp($a->id, $b->id));

        return $this->serializer->sha256(
            array_map(fn(TaskDescriptor $d) => $d->toArray(), $descriptors),
        );
    }
}
