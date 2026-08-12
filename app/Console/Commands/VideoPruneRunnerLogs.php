<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class VideoPruneRunnerLogs extends Command
{
    protected $signature = 'video:prune-runner-logs {--days= : Ghi đè số ngày giữ lại, mặc định video.runner.log_retention_days}';

    protected $description = 'Xoá log tiến trình nền (session_runner.py, render_queued_shots.py, video:build-plan...) cũ hơn hạn giữ';

    private const NAME_PATTERN = '/_\d{8}_\d{6}\.log$/';

    public function handle(): int
    {
        $logDir = (string) config('video.runner.log_dir', '');

        if ($logDir === '' || ! is_dir($logDir)) {
            $this->warn("Thu muc log chua cau hinh hoac khong ton tai ({$logDir}) — khong xoa gi.");

            return self::SUCCESS;
        }

        $days = (int) ($this->option('days') ?? config('video.runner.log_retention_days', 21));

        if ($days < 1) {
            $this->error("So ngay giu lai khong hop le ({$days}) — phai >= 1. Khong xoa gi.");

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days)->getTimestamp();

        $deleted = 0;
        $skipped = 0;

        foreach (glob(rtrim($logDir, '/\\').DIRECTORY_SEPARATOR.'*.log') ?: [] as $path) {
            if (! is_file($path) || ! preg_match(self::NAME_PATTERN, basename($path))) {
                continue;
            }

            $mtime = filemtime($path);
            if ($mtime === false || $mtime >= $cutoff) {
                continue;
            }

            if (@unlink($path)) {
                $deleted++;
            } else {
                $skipped++;
            }
        }

        Log::info('video:prune-runner-logs: hoan tat', [
            'log_dir' => $logDir, 'days' => $days, 'deleted' => $deleted, 'skipped' => $skipped,
        ]);
        $this->info("Da xoa {$deleted} file log cu hon {$days} ngay".($skipped ? ", bo qua {$skipped} file khong xoa duoc" : '').'.');

        return self::SUCCESS;
    }
}
