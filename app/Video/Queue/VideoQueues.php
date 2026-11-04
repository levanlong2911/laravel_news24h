<?php

declare(strict_types=1);

namespace App\Video\Queue;

final class VideoQueues
{
    public const CONNECTION = 'video';

    public const API = 'api';

    public const IMAGE = 'image';

    public const POLL = 'poll';

    public const DOWNLOAD = 'download';

    public const FFMPEG = 'ffmpeg';

    public const SYSTEM_REQUESTER = 'system';

    private const PREFIX = 'video-';

    public static function enabled(): bool
    {
        return (bool) config('video.async.enabled');
    }

    public static function name(string $kind, ?string $requesterId): string
    {
        return self::PREFIX.$kind.'-'.($requesterId === null || $requesterId === '' ? self::SYSTEM_REQUESTER : $requesterId);
    }

    /** @return ?array{kind: string, requester: string} */
    public static function parse(string $queue): ?array
    {
        if (preg_match('/^'.preg_quote(self::PREFIX, '/').'('.self::API.'|'.self::IMAGE.'|'.self::POLL.'|'.self::DOWNLOAD.'|'.self::FFMPEG.')-(.+)$/', $queue, $match) !== 1) {
            return null;
        }

        return ['kind' => $match[1], 'requester' => $match[2]];
    }

    public static function likePattern(): string
    {
        return self::PREFIX.'%';
    }

    public static function stateDirectory(): string
    {
        return (string) config('video.async.state_directory');
    }

    public static function heartbeatPath(): string
    {
        return self::stateDirectory().'/supervisor.json';
    }

    public static function lockPath(): string
    {
        return self::stateDirectory().'/supervisor.lock';
    }

    public static function workerDirectory(): string
    {
        return self::stateDirectory().'/workers';
    }

    public static function recoveryStatusPath(): string
    {
        return self::stateDirectory().'/recovery.json';
    }

    public static function jobTimeout(int $clientSeconds): int
    {
        return $clientSeconds + (int) config('video.async.execution_margin_seconds');
    }

    /** @return ?string why a job cannot be queued in one transaction with its pending run, null when it can */
    public static function problem(int $jobTimeoutSeconds): ?string
    {
        $video = (array) config('queue.connections.'.self::CONNECTION);

        if (($video['driver'] ?? null) !== 'database') {
            return 'Connection '.self::CONNECTION.' phải dùng driver database để lưu lượt chờ và job trong cùng một transaction.';
        }

        $connection = $video['connection'] ?? null;

        if ($connection !== null && $connection !== config('database.default')) {
            return 'Connection '.self::CONNECTION.' đang trỏ sang kết nối DB '.$connection.' — phải dùng cùng kết nối với ứng dụng để lưu lượt chờ và job trong cùng một transaction.';
        }

        $retryAfter = (int) ($video['retry_after'] ?? 0);

        if ($jobTimeoutSeconds >= $retryAfter) {
            return "Timeout của job ({$jobTimeoutSeconds} s) phải nhỏ hơn retry_after của connection ".self::CONNECTION." ({$retryAfter} s), nếu không một job đang chạy có thể bị nhận lần hai.";
        }

        return null;
    }
}
