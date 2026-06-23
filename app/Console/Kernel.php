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

        // Video pipeline: Fact Extractor -> Story Planner -> Script Generator,
        // mỗi 15 phút. Vẫn chạy sync (QUEUE_CONNECTION không đổi) -- chạy qua
        // CLI command nên không bị giới hạn execution-time như HTTP request.
        $schedule->command('video:process-articles')
            ->everyFifteenMinutes()
            ->withoutOverlapping()
            ->onFailure(fn() => \Log::error('Scheduler: video:process-articles failed'));

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
