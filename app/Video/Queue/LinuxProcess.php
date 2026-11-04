<?php

declare(strict_types=1);

namespace App\Video\Queue;

final class LinuxProcess
{
    /** @return ?string the start time of a process in clock ticks after boot, read from its /proc stat line; a reused pid does not repeat it */
    public static function startTime(string $stat): ?string
    {
        $close = strrpos($stat, ')');

        if ($close === false) {
            return null;
        }

        $fields = preg_split('/\s+/', trim(substr($stat, $close + 1))) ?: [];
        $start = $fields[19] ?? null;

        return is_string($start) && ctype_digit($start) ? $start : null;
    }

    /** @param list<string> $fragments what the command line must hold */
    public static function commandHolds(string $cmdline, array $fragments): bool
    {
        $command = str_replace("\0", ' ', $cmdline);

        foreach ($fragments as $fragment) {
            if ($fragment === '' || ! str_contains($command, $fragment)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> the arguments of a /proc cmdline */
    public static function arguments(string $cmdline): array
    {
        return array_values(array_filter(explode("\0", $cmdline), static fn (string $argument): bool => $argument !== ''));
    }

    /** @return ?string the video queue a command line works, when it is `queue:work` of this artisan; a shell that only quotes such a command is not one */
    public static function workerQueue(string $cmdline, string $artisan): ?string
    {
        $arguments = self::arguments($cmdline);

        if (! in_array($artisan, $arguments, true) || ! in_array('queue:work', $arguments, true)) {
            return null;
        }

        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--queue=')) {
                $queue = substr($argument, strlen('--queue='));

                return VideoQueues::parse($queue) === null ? null : $queue;
            }
        }

        return null;
    }

    /** @return list<array{pid: int, start: string, queue: string}> the live video workers of this artisan, found from /proc whether or not they were registered */
    public static function workers(string $artisan): array
    {
        $found = [];

        foreach (glob('/proc/[0-9]*', GLOB_ONLYDIR) ?: [] as $directory) {
            $cmdline = @file_get_contents($directory.'/cmdline');
            $queue = is_string($cmdline) ? self::workerQueue($cmdline, $artisan) : null;
            $pid = (int) basename($directory);
            $start = $queue === null ? null : self::start($pid);

            if ($queue !== null && $start !== null) {
                $found[] = ['pid' => $pid, 'start' => $start, 'queue' => $queue];
            }
        }

        return $found;
    }

    public static function start(int $pid): ?string
    {
        $stat = $pid > 0 ? @file_get_contents('/proc/'.$pid.'/stat') : false;

        return is_string($stat) ? self::startTime($stat) : null;
    }

    /** @param list<string> $fragments */
    public static function same(int $pid, mixed $start, array $fragments): bool
    {
        if (! is_string($start) || self::start($pid) !== $start) {
            return false;
        }

        $cmdline = @file_get_contents('/proc/'.$pid.'/cmdline');

        return is_string($cmdline) && self::commandHolds($cmdline, $fragments);
    }
}
