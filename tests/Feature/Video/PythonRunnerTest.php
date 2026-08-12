<?php

namespace Tests\Feature\Video;

use App\Services\PythonRunner;
use Tests\TestCase;

/**
 * Bắn tiến trình Python ở nền (§18.25).
 *
 * Test này KHÔNG sinh tiến trình thật. Nó khoá đúng thứ nguy hiểm: các đường
 * TỪ CHỐI bắn. Vì `spawn()` không chờ và không biết kết quả, nên mọi ca "không
 * bắn được" phải trả `false` một cách sạch sẽ — `false` là tín hiệu duy nhất
 * để Controller hiện đường lui chạy tay. Ném exception ở đây sẽ làm hỏng cả
 * nút bấm dù RenderPlan đã lưu xong.
 */
class PythonRunnerTest extends TestCase
{
    private function runner(): PythonRunner
    {
        return app(PythonRunner::class);
    }

    public function test_spawning_is_off_by_default_so_an_unconfigured_machine_still_works(): void
    {
        // Mặc định `runner_dir` rỗng. Máy chưa cấu hình KHÔNG được im lặng thử
        // chạy một đường dẫn không tồn tại — nếp cũ (chạy tay) phải nguyên vẹn.
        config(['video.runner.runner_dir' => '']);

        $this->assertFalse($this->runner()->spawn('session_runner.py', 'art_test'));
    }

    public function test_a_missing_script_is_refused_not_executed(): void
    {
        // Đường dẫn trỏ sai (vd repo Python bị di chuyển) — phải từ chối trước
        // khi chạm tới shell, không phải để shell báo lỗi trong file log mà
        // không ai đọc.
        config(['video.runner.runner_dir' => sys_get_temp_dir()]);

        $this->assertFalse($this->runner()->spawn('khong_ton_tai_'.uniqid().'.py', 'art_test'));
    }

    public function test_refusing_to_spawn_never_throws(): void
    {
        // Đây là bất biến quan trọng nhất của class: bắn hỏng KHÔNG được làm
        // hỏng nút bấm. RenderPlan đã lưu, session đã tồn tại — người dùng chỉ
        // mất phần tự động.
        config(['video.runner.runner_dir' => '/duong/dan/khong/ton/tai/'.uniqid()]);

        $result = $this->runner()->spawn('session_runner.py', 'art_test');

        $this->assertFalse($result);
    }

    public function test_the_manual_command_is_runnable_as_printed(): void
    {
        // Chuỗi này hiện thẳng lên màn hình cho người dùng dán vào terminal.
        // Sai một ký tự là họ dán vào rồi báo "không chạy được".
        $this->assertSame(
            'python tools/session_runner.py --session=art_abc_260731',
            $this->runner()->manualCommand('session_runner.py', 'art_abc_260731'),
        );
    }

    // ---- Chuỗi lệnh: chỗ dễ sai nhất, giờ đọc được mà không cần chạy ----

    private function buildCommand(string $bin, string $script, string $code, string $log, string $dir): string
    {
        $method = new \ReflectionMethod(PythonRunner::class, 'buildCommand');
        $method->setAccessible(true);

        return $method->invoke($this->runner(), $bin, $script, $code, $log, $dir);
    }

    /** @return array{0: string, 1: bool} lệnh + có phải Windows không */
    private function sampleCommand(): array
    {
        $isWindows = strncasecmp(PHP_OS_FAMILY, 'Windows', 7) === 0;

        return [
            $this->buildCommand(
                'D:/AI VIDEO/.venv/Scripts/python.exe',
                'D:/AI VIDEO/tools/session_runner.py',
                'art_abc_260731',
                'D:/logs/art_abc.log',
                'D:/AI VIDEO/tools',
            ),
            $isWindows,
        ];
    }

    public function test_the_empty_title_argument_after_start_is_present(): void
    {
        [$command, $isWindows] = $this->sampleCommand();

        if (! $isWindows) {
            $this->markTestSkipped('nhánh Windows — máy này là POSIX');
        }

        // THIẾU cặp `""` này thì `start` hiểu đường dẫn python (đang trong nháy
        // kép) là TIÊU ĐỀ CỬA SỔ, và lệnh im lặng không làm gì. Không lỗi,
        // không log, chỉ là không có gì xảy ra — rất khó truy.
        $this->assertStringContainsString('start "" /B ', $command);
    }

    public function test_windows_paths_use_backslash_and_never_end_with_one(): void
    {
        [$command, $isWindows] = $this->sampleCommand();

        if (! $isWindows) {
            $this->markTestSkipped('nhánh Windows — máy này là POSIX');
        }

        $this->assertStringContainsString('D:\AI VIDEO\tools\session_runner.py', $command);

        // Chỉ kiểm dấu `/` BÊN TRONG các chuỗi trong nháy kép (= đường dẫn).
        // Phần còn lại của lệnh vẫn phải có `/` vì đó là CỜ của cmd:
        // `cmd /c`, `cd /d`, `start /B` — khoá cả chuỗi là khoá nhầm.
        preg_match_all('/"([^"]*)"/', $command, $matches);

        foreach ($matches[1] as $quoted) {
            $this->assertStringNotContainsString(
                '/', $quoted,
                "đường dẫn [$quoted] còn dấu / — cmd.exe không chắc chắn với nó",
            );
        }

        // `\"` là dấu nháy ĐƯỢC ESCAPE với cmd — nó nuốt dấu đóng và phá vỡ
        // toàn bộ phần còn lại của lệnh. Đây là lý do phải rtrim dấu \ cuối.
        $this->assertStringNotContainsString('\\"', $command);
    }

    public function test_the_session_code_is_passed_through_untouched(): void
    {
        [$command] = $this->sampleCommand();

        // Mã session KHÔNG phải đường dẫn — normalize nó là sai. Khoá lại để
        // sau này ai đó "tiện tay" áp str_replace lên cả chuỗi sẽ thấy đỏ.
        $this->assertStringContainsString('--session=', $command);
        $this->assertStringContainsString('art_abc_260731', $command);
    }

    public function test_output_is_redirected_so_a_background_run_leaves_a_trace(): void
    {
        [$command] = $this->sampleCommand();

        // Tiến trình nền không ai nhìn stdout. Không chuyển hướng = hỏng trong
        // im lặng, không có gì để truy.
        $this->assertStringContainsString('2>&1', $command);
        $this->assertStringContainsString('art_abc.log', $command);
    }

    public function test_both_script_names_match_the_files_python_actually_has(): void
    {
        // Đổi tên script bên Python mà quên sửa hằng số ở đây thì `spawn()` sẽ
        // âm thầm trả false mãi mãi, và không ai hiểu vì sao "tự động" ngừng
        // hoạt động. Khoá cặp tên lại.
        $this->assertSame('session_runner.py', \App\Services\VideoSessionService::COMPOSE_SCRIPT);
        $this->assertSame('render_queued_shots.py', \App\Services\VideoSessionService::RENDER_SCRIPT);
    }

    // ---- §18.30: spawnArtisan() — cùng cơ chế bắn, đổi đối tượng ----

    public function test_manual_artisan_command_is_runnable_as_printed(): void
    {
        $this->assertSame(
            'php artisan video:build-plan --session=art_abc',
            $this->runner()->manualArtisanCommand('video:build-plan', ['--session=art_abc']),
        );
    }

    public function test_manual_artisan_command_with_no_args_has_no_trailing_space(): void
    {
        $this->assertSame(
            'php artisan video:build-plan',
            $this->runner()->manualArtisanCommand('video:build-plan', []),
        );
    }

    private function buildArtisanCommand(string $bin, string $command, array $args, string $log): string
    {
        $method = new \ReflectionMethod(PythonRunner::class, 'buildArtisanCommand');
        $method->setAccessible(true);

        return $method->invoke($this->runner(), $bin, $command, $args, $log);
    }

    /** @return array{0: string, 1: bool} lệnh + có phải Windows không */
    private function sampleArtisanCommand(): array
    {
        $isWindows = strncasecmp(PHP_OS_FAMILY, 'Windows', 7) === 0;

        return [
            $this->buildArtisanCommand(
                'php',
                'video:build-plan',
                ['--session=art_abc_260812'],
                'D:/logs/art_abc.log',
            ),
            $isWindows,
        ];
    }

    public function test_artisan_command_targets_the_laravel_repo_not_the_python_runner_dir(): void
    {
        [$command, $isWindows] = $this->sampleArtisanCommand();

        // Khác spawn(): lệnh Artisan chạy TỪ CHÍNH repo Laravel này, không
        // phải runner_dir (đó là thư mục repo Python, không liên quan).
        $expectedDir = $isWindows
            ? rtrim(str_replace('/', '\\', base_path()), '\\')
            : base_path();

        $this->assertStringContainsString($expectedDir, $command);
        $this->assertStringContainsString('artisan', $command);
    }

    public function test_artisan_command_passes_the_full_flag_through_untouched(): void
    {
        [$command] = $this->sampleArtisanCommand();

        $this->assertStringContainsString('--session=art_abc_260812', $command);
    }

    public function test_artisan_command_output_is_redirected_so_a_background_run_leaves_a_trace(): void
    {
        [$command] = $this->sampleArtisanCommand();

        $this->assertStringContainsString('2>&1', $command);
        $this->assertStringContainsString('art_abc.log', $command);
    }

    public function test_artisan_command_colon_in_command_name_is_sanitized_out_of_the_log_filename(): void
    {
        // `:` la ky tu cam trong ten file Windows — "video:build-plan" phai
        // duoc lam sach TRUOC khi lam ten file log, khong thi tao file that
        // bai va spawnArtisan() se tra false o moi lan goi.
        $method = new \ReflectionMethod(PythonRunner::class, 'logFileFor');
        $method->setAccessible(true);

        config(['video.runner.log_dir' => sys_get_temp_dir().'/pythonrunner_test_'.uniqid()]);

        $logFile = $method->invoke($this->runner(), str_replace(':', '_', 'video:build-plan'), 'art_abc');

        $this->assertStringNotContainsString(':', basename($logFile));
    }

    public function test_windows_artisan_command_has_the_empty_title_argument_after_start(): void
    {
        [$command, $isWindows] = $this->sampleArtisanCommand();

        if (! $isWindows) {
            $this->markTestSkipped('nhánh Windows — máy này là POSIX');
        }

        $this->assertStringContainsString('start "" /B ', $command);
    }
}
