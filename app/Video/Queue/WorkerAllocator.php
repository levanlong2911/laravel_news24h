<?php

declare(strict_types=1);

namespace App\Video\Queue;

final class WorkerAllocator
{
    public const BLOCKED_DATABASE = 'database';

    public const BLOCKED_MEMORY_UNKNOWN = 'memory_unknown';

    public const BLOCKED_MEMORY = 'memory';

    public const BLOCKED_MAX_PROCESSES = 'max_processes';

    public const BLOCKED_RAMP = 'ramp';

    /** @var list<string> the order a round offers a slot to each kind: work already sent to a provider and FFmpeg come before new api calls and image renders */
    private const ORDER = [VideoQueues::POLL, VideoQueues::DOWNLOAD, VideoQueues::FFMPEG, VideoQueues::API, VideoQueues::IMAGE];

    /** @var list<string> the kinds that start new provider work and so leave the completion reserve to the others */
    private const NEW_WORK = [VideoQueues::API, VideoQueues::IMAGE];

    /**
     * @param  array<string, array{ready: int, reserved: int}>  $queues  the jobs a worker may take now and the jobs taken by a live worker, per queue
     * @param  list<array{queue: string, kind: string, started_at: int}>  $workers  the workers alive now, the ones still starting included
     * @param  array<string, string>  $pointers  the requester each kind served last
     * @param  array{memory_available_mb: ?int, database_headroom: ?int, cpu_count: int}  $resources
     * @param  array{ram_reserve_mb: int, completion_reserve_mb: int, process_estimate_mb: array<string, int>, startup_seconds: int, cpu_reserve: int, max_processes: int, spawn_per_tick: int, db_connections_per_worker: int}  $settings
     * @return array{spawn: list<string>, pointers: array<string, string>, blocked: ?string, budget_mb: ?int, waiting: int}
     */
    public static function plan(array $queues, array $workers, array $pointers, array $resources, array $settings, int $now): array
    {
        $waiting = self::waiting($queues, $workers);
        $count = array_sum(array_map(static fn (array $byRequester): int => array_sum(array_column($byRequester, 'left')), $waiting));
        $result = ['spawn' => [], 'pointers' => $pointers, 'blocked' => null, 'budget_mb' => null, 'waiting' => $count];

        if ($count === 0) {
            return $result;
        }

        if ($resources['database_headroom'] === null || $resources['database_headroom'] < $settings['db_connections_per_worker']) {
            return ['blocked' => self::BLOCKED_DATABASE] + $result;
        }

        if ($resources['memory_available_mb'] === null) {
            return ['blocked' => self::BLOCKED_MEMORY_UNKNOWN] + $result;
        }

        $estimate = static fn (string $kind): int => max(1, (int) ($settings['process_estimate_mb'][$kind] ?? 100));
        $budget = $resources['memory_available_mb'] - $settings['ram_reserve_mb'];

        foreach ($workers as $worker) {
            if ($now - $worker['started_at'] < $settings['startup_seconds']) {
                $budget -= $estimate($worker['kind']);
            }
        }

        $connections = $resources['database_headroom'];
        $total = count($workers);
        $cpuSlots = max(1, $resources['cpu_count'] - $settings['cpu_reserve']) - count(array_filter($workers, static fn (array $worker): bool => $worker['kind'] === VideoQueues::FFMPEG));
        $spawn = [];
        $blocked = null;

        do {
            $progress = false;

            foreach (self::ORDER as $kind) {
                $requester = self::next($waiting[$kind] ?? [], $pointers[$kind] ?? null);

                if ($requester === null || ($kind === VideoQueues::FFMPEG && $cpuSlots <= 0)) {
                    continue;
                }

                if ($settings['spawn_per_tick'] > 0 && count($spawn) >= $settings['spawn_per_tick']) {
                    $blocked = self::BLOCKED_RAMP;

                    break 2;
                }

                if ($settings['max_processes'] > 0 && $total >= $settings['max_processes']) {
                    $blocked = self::BLOCKED_MAX_PROCESSES;

                    break 2;
                }

                if ($connections < $settings['db_connections_per_worker']) {
                    $blocked = self::BLOCKED_DATABASE;

                    break 2;
                }

                if ($budget < $estimate($kind) + (in_array($kind, self::NEW_WORK, true) ? $settings['completion_reserve_mb'] : 0)) {
                    $blocked = self::BLOCKED_MEMORY;

                    continue;
                }

                $spawn[] = $waiting[$kind][$requester]['queue'];
                $budget -= $estimate($kind);
                $connections -= $settings['db_connections_per_worker'];
                $total++;
                $pointers[$kind] = $requester;
                $progress = true;

                if ($kind === VideoQueues::FFMPEG) {
                    $cpuSlots--;
                }

                if (--$waiting[$kind][$requester]['left'] === 0) {
                    unset($waiting[$kind][$requester]);
                }
            }
        } while ($progress);

        return ['spawn' => $spawn, 'pointers' => $pointers, 'blocked' => $blocked, 'budget_mb' => $budget, 'waiting' => $count];
    }

    /**
     * @param  array<string, array{ready: int, reserved: int}>  $queues
     * @param  list<array{queue: string, kind: string, started_at: int}>  $workers
     * @return array<string, array<string, array{queue: string, left: int}>> per kind and requester, the jobs no live worker is about to take
     */
    private static function waiting(array $queues, array $workers): array
    {
        $alive = array_count_values(array_column($workers, 'queue'));
        $waiting = [];

        foreach ($queues as $queue => $count) {
            $parsed = VideoQueues::parse((string) $queue);

            if ($parsed === null) {
                continue;
            }

            $idle = max(0, ($alive[$queue] ?? 0) - $count['reserved']);
            $left = $count['ready'] - $idle;

            if ($left > 0) {
                $waiting[$parsed['kind']][$parsed['requester']] = ['queue' => (string) $queue, 'left' => $left];
            }
        }

        return $waiting;
    }

    /** @param array<string, array{queue: string, left: int}> $candidates */
    private static function next(array $candidates, ?string $after): ?string
    {
        if ($candidates === []) {
            return null;
        }

        $requesters = array_map('strval', array_keys($candidates));
        sort($requesters, SORT_STRING);

        foreach ($requesters as $requester) {
            if ($after === null || strcmp($requester, $after) > 0) {
                return $requester;
            }
        }

        return $requesters[0];
    }
}
