<?php

namespace App\Console\Commands;

use App\Jobs\Video\ProbeVideoJob;
use App\Video\Queue\VideoQueues;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class VideoQueueProbe extends Command
{
    protected $signature = 'video:queue-probe
        {--people=6 : Số người giả lập, mỗi người một hàng đợi riêng}
        {--jobs=2 : Số job của mỗi người}
        {--seconds=20 : Mỗi job ngủ bao nhiêu giây}
        {--kind=api : Loại hàng đợi: api, image, poll, download hoặc ffmpeg}
        {--no-wait : Chỉ đưa job vào hàng đợi, không chờ kết quả}';

    protected $description = 'Thử hàng đợi video trên server bằng job ngủ vô hại: không gọi model, không tính phí; báo số job chạy cùng lúc và thời gian chờ của từng người';

    public function handle(): int
    {
        $people = (int) $this->option('people');
        $jobs = (int) $this->option('jobs');
        $seconds = (int) $this->option('seconds');
        $kind = (string) $this->option('kind');

        if ($people < 1 || $jobs < 1 || $seconds < 1 || ! in_array($kind, [VideoQueues::API, VideoQueues::IMAGE, VideoQueues::POLL, VideoQueues::DOWNLOAD, VideoQueues::FFMPEG], true)) {
            $this->error('--people, --jobs, --seconds phải từ 1 trở lên; --kind là api, image, poll, download hoặc ffmpeg.');

            return self::FAILURE;
        }

        $problem = VideoQueues::problem(VideoQueues::jobTimeout($seconds + 60));

        if ($problem !== null) {
            $this->error($problem);

            return self::FAILURE;
        }

        $runId = now()->format('Ymd-His').'-'.Str::lower(Str::random(4));
        File::ensureDirectoryExists(ProbeVideoJob::directory($runId));
        $sentAt = microtime(true);

        for ($job = 1; $job <= $jobs; $job++) {
            for ($person = 1; $person <= $people; $person++) {
                ProbeVideoJob::dispatch($runId, "p{$person}-j{$job}", $seconds)
                    ->onConnection(VideoQueues::CONNECTION)
                    ->onQueue(VideoQueues::name($kind, 'probe'.$person));
            }
        }

        $total = $people * $jobs;
        $this->info("Đã đưa {$total} job ngủ {$seconds} s vào {$people} hàng đợi video-{$kind}-probe1…{$people} (lượt {$runId}).");

        if ($this->option('no-wait')) {
            $this->line('Xem kết quả trong '.ProbeVideoJob::directory($runId));

            return self::SUCCESS;
        }

        $deadline = $sentAt + $total * $seconds + 180;
        $done = [];

        while (microtime(true) < $deadline) {
            $done = $this->results($runId);
            $this->output->write("\rXong {$done->count()}/{$total} job…");

            if ($done->count() >= $total) {
                break;
            }

            if ($done->isEmpty() && microtime(true) - $sentAt > $seconds + 90) {
                $this->newLine();
                $this->error('Chưa job nào xong sau '.($seconds + 90).' s — bộ điều phối có đang chạy không? Chạy php artisan video:queue-check.');

                return self::FAILURE;
            }

            usleep(2_000_000);
        }

        $this->newLine();
        $this->report($done, $total, $sentAt, $seconds, $people);
        File::deleteDirectory(ProbeVideoJob::directory($runId));

        return $done->count() >= $total ? self::SUCCESS : self::FAILURE;
    }

    /** @return \Illuminate\Support\Collection<int, array{label: string, queue: string, pid: int, started: float, ended: float, memory_mb: float}> */
    private function results(string $runId): \Illuminate\Support\Collection
    {
        return collect(glob(ProbeVideoJob::directory($runId).'/*.json') ?: [])
            ->map(static fn (string $path): ?array => json_decode((string) @file_get_contents($path), true))
            ->filter(static fn (?array $row): bool => is_array($row) && isset($row['started'], $row['ended']))
            ->values();
    }

    /** @param \Illuminate\Support\Collection<int, array{label: string, queue: string, pid: int, started: float, ended: float, memory_mb: float}> $done */
    private function report(\Illuminate\Support\Collection $done, int $total, float $sentAt, int $seconds, int $people): void
    {
        $events = $done->flatMap(static fn (array $row): array => [[$row['started'], 1], [$row['ended'], -1]])->sortBy(static fn (array $event): string => sprintf('%020.6f%d', $event[0], $event[1] + 1))->values();
        $running = 0;
        $peak = 0;

        foreach ($events as [, $step]) {
            $running += $step;
            $peak = max($peak, $running);
        }

        $first = $done->groupBy(static fn (array $row): string => explode('-', $row['label'])[0])
            ->map(static fn ($rows): float => round($rows->min('started') - $sentAt, 1))
            ->sortKeys();
        $wall = $done->isEmpty() ? 0.0 : round($done->max('ended') - $sentAt, 1);

        $this->table(['Người', 'Chờ tới job đầu tiên (s)'], $first->map(static fn (float $wait, string $person): array => [$person, $wait])->values()->all());
        $this->line("Xong {$done->count()}/{$total} job · tổng thời gian {$wall} s (chạy lần lượt sẽ mất ".($total * $seconds).' s) · tối đa '.$peak.' job chạy cùng lúc · '.$done->pluck('pid')->unique()->count().' tiến trình worker · RAM đỉnh mỗi job '.$done->max('memory_mb').' MB.');
        $this->line($peak >= min($people, $total)
            ? 'Song song: mọi người đều có job chạy cùng lúc, không ai chờ ai.'
            : "Chưa đủ song song: chỉ {$peak} job chạy cùng lúc cho {$people} người — xem RAM trống, ram_reserve_mb và nhịp tim trong video:queue-check.");
    }
}
