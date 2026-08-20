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
            ->onFailure(fn () => \Log::error('Scheduler: news:dispatch failed'));

        // Tự xóa raw_articles hết hạn (expires_at < now, TTL 24h)
        $schedule->command('model:prune', ['--model' => \App\Models\RawArticle::class])
            ->hourly();

        // Tự xóa articles hết hạn (TTL 48h) — mỗi ngày lúc 3:00 AM
        $schedule->command('model:prune', ['--model' => \App\Models\Article::class])
            ->dailyAt('03:00');

        // Shot claim/lease mặc định 600s (VideoSessionController::apiClaim) —
        // 5 phút bắt kịp trong vòng một nửa chu kỳ lease, không quá dồn dập.
        $schedule->command('video:reclaim-expired-leases')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->onFailure(fn () => \Log::error('Scheduler: video:reclaim-expired-leases failed'));

        // O thiet ke anh dung cung chu ky lease 600s, nen cung nhip thu hoi.
        $schedule->command('video:reclaim-expired-design-image-leases')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->onFailure(fn () => \Log::error('Scheduler: video:reclaim-expired-design-image-leases failed'));

        $schedule->command('video:prune-runner-logs')
            ->dailyAt('03:30')
            ->withoutOverlapping()
            ->onFailure(fn () => \Log::error('Scheduler: video:prune-runner-logs failed'));
    }

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
