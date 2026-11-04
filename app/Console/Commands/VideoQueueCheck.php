<?php

namespace App\Console\Commands;

use App\Services\Video\ImageRenderQueue;
use App\Video\Queue\VideoQueues;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class VideoQueueCheck extends Command
{
    protected $signature = 'video:queue-check';

    protected $description = 'Kiểm tra trước khi bật VIDEO_ASYNC_ENABLED: migration, cấu hình queue, quyền ghi file, PHP CLI/pcntl, bộ điều phối và thu hồi';

    public function handle(): int
    {
        $rows = [];
        $failed = false;
        $check = function (string $name, bool $ok, string $detail, bool $required = true) use (&$rows, &$failed): void {
            $rows[] = [$ok ? 'ĐẠT' : ($required ? 'LỖI' : 'CẢNH BÁO'), $name, $detail];
            $failed = $failed || (! $ok && $required);
        };

        $check('Công tắc VIDEO_ASYNC_ENABLED', true, VideoQueues::enabled() ? 'đang BẬT — yêu cầu mới vào hàng đợi' : 'đang TẮT — yêu cầu mới chạy ngay trong request', false);

        try {
            DB::selectOne('SELECT 1');
            $database = null;
        } catch (\Throwable $e) {
            $database = mb_substr($e->getMessage(), 0, 300);
        }

        $check('Kết nối DB', $database === null, $database ?? 'kết nối '.config('database.default').' được');

        if ($database === null) {
            $check('Cột request_snapshot', Schema::hasColumn('video_planning_stages', 'request_snapshot'), 'video_planning_stages.request_snapshot — thiếu thì chạy php artisan migrate (migration 2026_11_04_120000)');
            $check('Cột render_queue', Schema::hasColumn('video_design_images', 'render_queue'), 'video_design_images.render_queue — thiếu thì chạy php artisan migrate (migration 2026_11_04_130000); ảnh chạy nền cần cột này');
            $check('Bảng jobs và failed_jobs', Schema::hasTable('jobs') && Schema::hasTable('failed_jobs'), 'hàng đợi database và nơi lưu lỗi kỹ thuật');
        }

        $longest = max(
            VideoQueues::jobTimeout(max(array_map('intval', [
                config('video.superyacht.locations.timeout_seconds'),
                config('video.moto.locations.timeout_seconds'),
                config('video.superyacht.dna.timeout_seconds'),
                config('video.superyacht.design.timeout_seconds'),
                config('video.superyacht.model_sheet.qa.timeout_seconds'),
            ]))),
            ImageRenderQueue::jobSeconds(),
        );
        $problem = VideoQueues::problem($longest);
        $check('Connection '.VideoQueues::CONNECTION, $problem === null, $problem ?? 'driver database, cùng kết nối DB với ứng dụng, job dài nhất '.$longest.' s < retry_after '.config('queue.connections.'.VideoQueues::CONNECTION.'.retry_after').' s');

        $shell = function_exists('shell_exec') && ! in_array('shell_exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true);
        $linux = PHP_OS_FAMILY === 'Linux';
        $check('PHP CLI', PHP_VERSION_ID >= 80200, 'PHP '.PHP_VERSION.' (cần 8.2 trở lên)');
        $check('Extension pcntl', extension_loaded('pcntl'), 'cần để ép timeout của job và dừng mềm bộ điều phối');
        $check('Hàm shell_exec', $shell, 'bộ điều phối dùng để bật worker chạy nền');

        foreach (['bash', 'nohup'] as $program) {
            $path = $shell && $linux ? trim((string) shell_exec('command -v '.escapeshellarg($program).' 2>/dev/null')) : '';
            $check('Lệnh '.$program, $path !== '', $path !== '' ? $path : 'không tìm thấy — bộ điều phối bật worker bằng bash -c … nohup', $linux);
        }

        $check('Đọc RAM trống', is_readable('/proc/meminfo'), '/proc/meminfo — thiếu thì bộ điều phối không cấp worker (Windows: dùng --debug)');

        foreach ([storage_path('logs'), storage_path('framework/cache'), VideoQueues::stateDirectory()] as $directory) {
            File::ensureDirectoryExists($directory);
            $check('Quyền ghi '.str_replace(base_path().DIRECTORY_SEPARATOR, '', $directory), is_writable($directory), $directory);
        }

        $heartbeat = json_decode((string) @file_get_contents(VideoQueues::heartbeatPath()), true);
        $stale = (int) config('video.async.supervisor.heartbeat_stale_seconds');
        $beat = is_array($heartbeat) ? time() - (int) ($heartbeat['at'] ?? 0) : null;
        $check('Bộ điều phối đang chạy', $beat !== null && $beat <= $stale && ($heartbeat['mode'] ?? null) === 'run' && ! isset($heartbeat['error']), $beat === null ? 'chưa có nhịp tim — chưa từng chạy video:queue-supervisor' : "nhịp tim cách đây {$beat} s, chế độ ".($heartbeat['mode'] ?? '?').(isset($heartbeat['error']) ? ', lỗi: '.$heartbeat['error'] : ''), VideoQueues::enabled());

        $recovery = json_decode((string) @file_get_contents(VideoQueues::recoveryStatusPath()), true);
        $last = is_array($recovery) && is_array($recovery['last'] ?? null) ? $recovery['last'] : null;
        $okAt = is_array($recovery) ? (int) ($recovery['ok_at'] ?? 0) : 0;
        $window = 3 * (int) config('video.async.supervisor.recovery_every_seconds');
        $check(
            'Thu hồi lượt hết lease',
            ($last['result'] ?? null) === 'success' && time() - (int) ($last['at'] ?? 0) <= $window,
            match (true) {
                $last === null => 'chưa hoàn tất lần nào',
                $last['result'] !== 'success' => 'lần chạy gần nhất LỖI cách đây '.(time() - (int) $last['at']).' s: '.($last['error'] ?? '?').($okAt > 0 ? ' (thành công gần nhất cách đây '.(time() - $okAt).' s)' : ''),
                default => 'hoàn tất gần nhất cách đây '.(time() - (int) $last['at']).' s',
            },
            VideoQueues::enabled(),
        );

        try {
            $video = (array) config('queue.connections.'.VideoQueues::CONNECTION);
            $waiting = DB::connection($video['connection'] ?? null)->table((string) $video['table'])->where('queue', 'like', VideoQueues::likePattern())->count();
            $failedJobs = DB::table('failed_jobs')->where('queue', 'like', VideoQueues::likePattern())->where('failed_at', '>=', now()->subDay())->count();
            $check('Hàng đợi video', true, "{$waiting} job đang chờ hoặc đang chạy, {$failedJobs} job lỗi kỹ thuật trong 24 giờ", false);
        } catch (\Throwable $e) {
            $check('Hàng đợi video', false, 'không đọc được: '.$e->getMessage());
        }

        $this->table(['Kết quả', 'Mục', 'Chi tiết'], $rows);
        $this->line($failed ? 'CHƯA SẴN SÀNG — sửa các mục LỖI trước khi bật VIDEO_ASYNC_ENABLED=true.' : 'Sẵn sàng.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
