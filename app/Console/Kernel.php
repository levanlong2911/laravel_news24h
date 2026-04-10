<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        // Fetch trending news mỗi 6 tiếng → lưu vào raw_articles (không AI)
        $schedule->command('news:dispatch')
            ->everySixHours()
            ->withoutOverlapping()
            ->onFailure(fn() => \Log::error('Scheduler: news:dispatch failed'));

        // Tự xóa raw_articles hết hạn (expires_at < now, TTL 24h)
        $schedule->command('model:prune', ['--model' => \App\Models\RawArticle::class])
            ->hourly();

        // Tự xóa articles hết hạn (TTL 48h) — mỗi ngày lúc 3:00 AM
        $schedule->command('model:prune', ['--model' => \App\Models\Article::class])
            ->dailyAt('03:00');
    }

    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
