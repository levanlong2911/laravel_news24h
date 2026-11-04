<?php

namespace App\Console\Commands;

use App\Video\Queue\LinuxProcess;
use App\Video\Queue\VideoQueues;
use App\Video\Queue\WorkerAllocator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class VideoQueueSupervisor extends Command
{
    protected $signature = 'video:queue-supervisor
        {--debug : Chạy từng job ngay trong terminal, không kiểm RAM và không cần pcntl (Windows, local)}
        {--dry-run : Chỉ ghi log quyết định cấp worker, không bật tiến trình và không thu hồi}
        {--stop-when-empty : Thoát khi không còn job chờ và không còn worker}';

    protected $description = 'Bộ điều phối hàng đợi video: bật worker một-job tăng dần theo RAM trống và kết nối DB, xoay vòng công bằng theo người gửi, thu hồi lượt hết lease';

    private const RECOVERY_COMMANDS = [
        'video:recover-expired-planning-stages' => 1,
        'video:recover-expired-render-leases' => 1,
        'video:reclaim-expired-design-image-leases' => 5,
    ];

    private bool $stopping = false;

    /** @var array<string, string> */
    private array $pointers = [];

    /** @var array<string, int> */
    private array $observedMb = [];

    /** @var array<string, int> */
    private array $recoveryStartedAt = [];

    /** @var array<string, array{pid: int, start: ?string}> */
    private array $recoveryProcesses = [];

    private ?string $lastBlocked = null;

    private int $lastBlockedLogAt = 0;

    /** @var resource|null */
    private $lock = null;

    public function handle(): int
    {
        File::ensureDirectoryExists(VideoQueues::workerDirectory());
        $this->lock = fopen(VideoQueues::lockPath(), PHP_OS_FAMILY === 'Windows' ? 'c' : 'ce') ?: null;

        if ($this->lock === null || ! flock($this->lock, LOCK_EX | LOCK_NB)) {
            $this->error('Đã có một bộ điều phối khác đang chạy (hoặc không mở được file khoá) — thoát.');

            return self::FAILURE;
        }

        $debug = (bool) $this->option('debug');

        if (! $debug && PHP_OS_FAMILY === 'Windows') {
            $this->error('Chế độ chạy thật cần Linux (đọc /proc, bật worker nền bằng nohup) — trên Windows dùng --debug.');
            $this->unlock();

            return self::FAILURE;
        }

        $this->listenForSignals();
        $dryRun = (bool) $this->option('dry-run');
        $mode = $debug ? 'debug' : ($dryRun ? 'dry-run' : 'run');
        $this->info("Bộ điều phối hàng đợi video bắt đầu (chế độ {$mode}, pid ".getmypid().').');
        Log::info('video queue supervisor: started', ['mode' => $mode, 'pid' => getmypid()]);

        while (! $this->stopping) {
            $tick = microtime(true);
            $workers = $debug ? [] : $this->workers();

            try {
                $queues = $this->queues();
                $headroom = $debug ? PHP_INT_MAX : $this->databaseHeadroom($workers);
            } catch (\Throwable $e) {
                Log::error('video queue supervisor: the database cannot be read, no worker is started', ['exception' => $e]);
                $this->error('Không đọc được DB — ngừng cấp worker: '.$e->getMessage());
                $this->heartbeat(['mode' => $mode, 'error' => 'database: '.mb_substr($e->getMessage(), 0, 300)]);
                $this->pause($tick);

                continue;
            }

            if ($debug) {
                $ran = $this->runInline($queues);
                $this->heartbeat(['mode' => $mode, 'waiting' => array_sum(array_column($queues, 'ready'))]);

                if (! $ran && $this->option('stop-when-empty') && array_sum(array_column($queues, 'ready')) === 0) {
                    break;
                }

                $this->pause($tick);

                continue;
            }

            $memory = $this->memoryAvailableMb();
            $plan = WorkerAllocator::plan($queues, $workers, $this->pointers, [
                'memory_available_mb' => $memory,
                'database_headroom' => $headroom,
                'cpu_count' => $this->cpuCount(),
            ], $this->settings(), time());
            $this->pointers = $plan['pointers'];
            $this->report($plan, $workers, $memory, $headroom);

            if (! $dryRun) {
                foreach ($plan['spawn'] as $queue) {
                    $this->spawn($queue);
                }

                $this->recover();
            }

            $this->heartbeat([
                'mode' => $mode,
                'workers' => count($workers) + ($dryRun ? 0 : count($plan['spawn'])),
                'waiting' => $plan['waiting'],
                'blocked' => $plan['blocked'],
                'budget_mb' => $plan['budget_mb'],
                'memory_available_mb' => $memory,
                'database_headroom' => $headroom,
                'estimates_mb' => $this->settings()['process_estimate_mb'],
            ]);

            if ($this->option('stop-when-empty') && $plan['waiting'] === 0 && $workers === [] && array_sum(array_column($queues, 'reserved')) === 0) {
                break;
            }

            $this->pause($tick);
        }

        $this->drain();
        $this->unlock();
        Log::info('video queue supervisor: stopped', ['pid' => getmypid()]);

        return self::SUCCESS;
    }

    /** @return array<string, array{ready: int, reserved: int}> per queue, the jobs a worker may take now, a reservation its worker abandoned included, and the jobs a live worker holds */
    private function queues(): array
    {
        $video = (array) config('queue.connections.'.VideoQueues::CONNECTION);
        $now = time();
        $expired = $now - (int) $video['retry_after'];
        $rows = DB::connection($video['connection'] ?? null)->table((string) $video['table'])
            ->where('queue', 'like', VideoQueues::likePattern())
            ->selectRaw(
                'queue, SUM(CASE WHEN (reserved_at IS NULL AND available_at <= ?) OR reserved_at <= ? THEN 1 ELSE 0 END) AS ready, SUM(CASE WHEN reserved_at > ? THEN 1 ELSE 0 END) AS reserved',
                [$now, $expired, $expired],
            )
            ->groupBy('queue')
            ->get();
        $queues = [];

        foreach ($rows as $row) {
            $queues[(string) $row->queue] = ['ready' => (int) $row->ready, 'reserved' => (int) $row->reserved];
        }

        return $queues;
    }

    /**
     * @param  list<array{queue: string, kind: string, started_at: int, pid: int}>  $workers
     * @return ?int the connections left under the ceiling once the workers still starting have opened theirs, null when the server cannot say
     */
    private function databaseHeadroom(array $workers): ?int
    {
        $connected = DB::selectOne("SHOW STATUS LIKE 'Threads_connected'");
        $ceiling = DB::selectOne("SHOW VARIABLES LIKE 'max_connections'");

        if ($connected === null || $ceiling === null || (int) $ceiling->Value === 0) {
            return null;
        }

        $starting = count(array_filter($workers, static fn (array $worker): bool => time() - $worker['started_at'] < (int) config('video.async.supervisor.startup_seconds')));

        return (int) floor((int) $ceiling->Value * (float) config('video.async.supervisor.db_connection_ceiling'))
            - (int) $connected->Value
            - $starting * (int) config('video.async.supervisor.db_connections_per_worker');
    }

    private function memoryAvailableMb(): ?int
    {
        $info = @file_get_contents('/proc/meminfo');

        if (! is_string($info) || preg_match('/^MemAvailable:\s+(\d+)\s+kB/m', $info, $match) !== 1) {
            return null;
        }

        return intdiv((int) $match[1], 1024);
    }

    private function cpuCount(): int
    {
        $info = @file_get_contents('/proc/cpuinfo');

        return is_string($info) ? max(1, preg_match_all('/^processor\s*:/m', $info)) : 1;
    }

    /** @return array{ram_reserve_mb: int, completion_reserve_mb: int, process_estimate_mb: array<string, int>, startup_seconds: int, cpu_reserve: int, max_processes: int, spawn_per_tick: int, db_connections_per_worker: int} */
    private function settings(): array
    {
        $settings = (array) config('video.async.supervisor');
        $estimates = [];

        foreach ((array) $settings['process_estimate_mb'] as $kind => $mb) {
            $estimates[(string) $kind] = max((int) $mb, $this->observedMb[$kind] ?? 0);
        }

        return [
            'ram_reserve_mb' => (int) $settings['ram_reserve_mb'],
            'completion_reserve_mb' => (int) $settings['completion_reserve_mb'],
            'process_estimate_mb' => $estimates,
            'startup_seconds' => (int) $settings['startup_seconds'],
            'cpu_reserve' => (int) $settings['cpu_reserve'],
            'max_processes' => (int) $settings['max_processes'],
            'spawn_per_tick' => (int) $settings['spawn_per_tick'],
            'db_connections_per_worker' => (int) $settings['db_connections_per_worker'],
        ];
    }

    /** @return list<array{queue: string, kind: string, started_at: int, pid: int}> the live workers of this app: the registered ones checked to be the same process, and any found in /proc without a registry entry adopted */
    private function workers(): array
    {
        $registered = [];

        foreach (glob(VideoQueues::workerDirectory().'/*.json') ?: [] as $file) {
            $worker = json_decode((string) @file_get_contents($file), true);
            $pid = is_array($worker) ? (int) ($worker['pid'] ?? 0) : 0;

            if (! is_array($worker) || ! LinuxProcess::same($pid, $worker['start'] ?? null, [base_path('artisan'), 'queue:work', '--queue='.($worker['queue'] ?? '')])) {
                @unlink($file);

                continue;
            }

            $registered[$pid] = $worker;
        }

        foreach (LinuxProcess::workers(base_path('artisan')) as $process) {
            if (isset($registered[$process['pid']])) {
                continue;
            }

            $worker = ['pid' => $process['pid'], 'start' => $process['start'], 'queue' => $process['queue'], 'kind' => (string) VideoQueues::parse($process['queue'])['kind'], 'started_at' => time()];
            $this->register($worker);
            $registered[$process['pid']] = $worker;
            Log::warning('video queue supervisor: adopted a worker that had no registry entry', ['pid' => $process['pid'], 'queue' => $process['queue']]);
        }

        $workers = [];

        foreach ($registered as $pid => $worker) {
            $rss = $this->residentMb($pid);

            if ($rss !== null && time() - (int) $worker['started_at'] >= (int) config('video.async.supervisor.startup_seconds')) {
                $this->observedMb[$worker['kind']] = max($this->observedMb[$worker['kind']] ?? 0, $rss);
            }

            $workers[] = ['queue' => (string) $worker['queue'], 'kind' => (string) $worker['kind'], 'started_at' => (int) $worker['started_at'], 'pid' => (int) $pid];
        }

        return $workers;
    }

    /** @param array{pid: int, start: string, queue: string, kind: string, started_at: int} $worker */
    private function register(array $worker): void
    {
        $path = VideoQueues::workerDirectory().'/'.$worker['pid'].'.json';
        $temporary = $path.'.'.getmypid().'.tmp';

        if (@file_put_contents($temporary, (string) json_encode($worker)) === false || ! @rename($temporary, $path)) {
            @unlink($temporary);
            Log::error('video queue supervisor: a worker could not be registered, the next /proc scan adopts it', ['pid' => $worker['pid']]);
        }
    }

    private function residentMb(int $pid): ?int
    {
        $status = @file_get_contents('/proc/'.$pid.'/status');

        return is_string($status) && preg_match('/^VmRSS:\s+(\d+)\s+kB/m', $status, $match) === 1 ? intdiv((int) $match[1], 1024) : null;
    }

    private function spawn(string $queue): void
    {
        $parsed = VideoQueues::parse($queue);

        if ($parsed === null) {
            return;
        }

        $pid = $this->detach([
            'queue:work', VideoQueues::CONNECTION, '--queue='.$queue, '--max-jobs=1', '--stop-when-empty', '--tries=1', '--sleep=0',
            '--timeout='.max(60, (int) config('queue.connections.'.VideoQueues::CONNECTION.'.retry_after') - 60),
        ]);
        $start = $pid === null ? null : LinuxProcess::start($pid);

        if ($pid === null || $start === null) {
            Log::error('video queue supervisor: a worker could not be started or ended at once', ['queue' => $queue, 'pid' => $pid]);

            return;
        }

        $this->register(['pid' => $pid, 'start' => $start, 'queue' => $queue, 'kind' => $parsed['kind'], 'started_at' => time()]);
        Log::info('video queue supervisor: started a worker', ['queue' => $queue, 'pid' => $pid]);
    }

    /** @param list<string> $arguments the artisan command a background process runs; it inherits no descriptor of the supervisor, so the lock, the database connection and the logs die with the supervisor */
    private function detach(array $arguments): ?int
    {
        $command = sprintf(
            'bash -c %s %s %s %s >> %s 2>&1 < /dev/null & echo $!',
            escapeshellarg('for fd in /proc/$$/fd/*; do n=${fd##*/}; [ "$n" -gt 2 ] && eval "exec $n>&-"; done 2>/dev/null; exec nohup "$0" "$@"'),
            escapeshellarg(PHP_BINARY),
            escapeshellarg(base_path('artisan')),
            implode(' ', array_map('escapeshellarg', $arguments)),
            escapeshellarg(storage_path('logs/video-queue-workers.log')),
        );
        $pid = (int) trim((string) shell_exec($command));

        return $pid > 0 ? $pid : null;
    }

    private function recover(): void
    {
        $every = (int) config('video.async.supervisor.recovery_every_seconds');

        foreach (self::RECOVERY_COMMANDS as $command => $periods) {
            if (time() - ($this->recoveryStartedAt[$command] ?? 0) < $every * $periods) {
                continue;
            }

            $running = $this->recoveryProcesses[$command] ?? null;

            if ($running !== null && LinuxProcess::same($running['pid'], $running['start'], [base_path('artisan'), $command])) {
                continue;
            }

            $this->recoveryStartedAt[$command] = time();
            $pid = $this->detach([$command]);

            if ($pid === null) {
                Log::error('video queue supervisor: a recovery command could not be started', ['command' => $command]);

                continue;
            }

            $this->recoveryProcesses[$command] = ['pid' => $pid, 'start' => LinuxProcess::start($pid)];
        }
    }

    /** @param array<string, array{ready: int, reserved: int}> $queues */
    private function runInline(array $queues): bool
    {
        $plan = WorkerAllocator::plan($queues, [], $this->pointers, ['memory_available_mb' => PHP_INT_MAX, 'database_headroom' => PHP_INT_MAX, 'cpu_count' => 2], ['spawn_per_tick' => 1] + $this->settings(), time());
        $this->pointers = $plan['pointers'];

        if (time() - ($this->recoveryStartedAt['inline'] ?? 0) >= (int) config('video.async.supervisor.recovery_every_seconds')) {
            $this->recoveryStartedAt['inline'] = time();
            $this->call('video:recover-expired-planning-stages');
        }

        foreach ($plan['spawn'] as $queue) {
            $this->line("Chạy một job của hàng đợi {$queue} ngay trong terminal…");
            $this->call('queue:work', [
                'connection' => VideoQueues::CONNECTION,
                '--queue' => $queue,
                '--max-jobs' => 1,
                '--stop-when-empty' => true,
                '--tries' => 1,
                '--sleep' => 0,
            ]);
        }

        return $plan['spawn'] !== [];
    }

    /**
     * @param  array{spawn: list<string>, pointers: array<string, string>, blocked: ?string, budget_mb: ?int, waiting: int}  $plan
     * @param  list<array<string, mixed>>  $workers
     */
    private function report(array $plan, array $workers, ?int $memory, ?int $headroom): void
    {
        $context = ['spawn' => $plan['spawn'], 'workers' => count($workers), 'waiting' => $plan['waiting'], 'blocked' => $plan['blocked'], 'budget_mb' => $plan['budget_mb'], 'memory_available_mb' => $memory, 'database_headroom' => $headroom, 'estimates_mb' => $this->settings()['process_estimate_mb']];

        if ($plan['spawn'] !== []) {
            Log::info('video queue supervisor: decision', $context);
            $this->line('Cấp '.count($plan['spawn']).' worker: '.implode(', ', $plan['spawn']).' · đang chạy '.count($workers).' · RAM trống '.($memory ?? '?').' MB · còn '.($headroom ?? '?').' kết nối DB');
        }

        if ($plan['blocked'] !== null && $plan['blocked'] !== WorkerAllocator::BLOCKED_RAMP && ($plan['blocked'] !== $this->lastBlocked || time() - $this->lastBlockedLogAt >= 60)) {
            $this->lastBlockedLogAt = time();
            $message = match ($plan['blocked']) {
                WorkerAllocator::BLOCKED_MEMORY_UNKNOWN => 'Không đọc được RAM trống (/proc/meminfo) — ngừng cấp worker mới; ở Windows dùng --debug.',
                WorkerAllocator::BLOCKED_DATABASE => 'Kết nối DB đã gần trần hoặc không đọc được — ngừng cấp worker mới.',
                WorkerAllocator::BLOCKED_MEMORY => 'RAM trống chạm mức dự phòng — job còn lại chờ worker đang chạy xong.',
                default => 'Đã chạm trần số tiến trình cấu hình — job còn lại chờ.',
            };
            Log::warning('video queue supervisor: '.$message, $context);
            $this->warn($message);
        }

        $this->lastBlocked = $plan['blocked'];
    }

    /** @param array<string, mixed> $state */
    private function heartbeat(array $state): void
    {
        @file_put_contents(VideoQueues::heartbeatPath(), (string) json_encode(['pid' => getmypid(), 'at' => time(), 'recovery_started_at' => $this->recoveryStartedAt] + $state, JSON_UNESCAPED_UNICODE));
    }

    private function pause(float $tick): void
    {
        $left = (float) config('video.async.supervisor.tick_seconds') - (microtime(true) - $tick);

        if ($left > 0 && ! $this->stopping) {
            usleep((int) ($left * 1_000_000));
        }
    }

    private function drain(): void
    {
        if ($this->option('debug')) {
            return;
        }

        $until = time() + (int) config('video.async.supervisor.stop_wait_seconds');
        $this->info('Ngừng cấp việc; chờ worker đang chạy xong…');

        while (($left = count($this->workers())) > 0 && time() < $until) {
            sleep(1);
        }

        if ($left > 0) {
            Log::warning('video queue supervisor: stopping while workers still run; the next supervisor adopts them from the worker registry', ['workers' => $left]);
            $this->warn("Còn {$left} worker đang chạy — chúng chạy tiếp tới hết job, bộ điều phối sau nhận quản lý qua danh sách worker.");
        }
    }

    private function unlock(): void
    {
        if ($this->lock !== null) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
            $this->lock = null;
        }
    }

    private function listenForSignals(): void
    {
        if (! extension_loaded('pcntl')) {
            return;
        }

        pcntl_async_signals(true);

        foreach ([SIGTERM, SIGINT, SIGQUIT] as $signal) {
            pcntl_signal($signal, function (): void {
                $this->stopping = true;
            });
        }
    }
}
