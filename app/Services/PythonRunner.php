<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Bắn một script Python ở NỀN rồi trả về ngay — §18.25.
 *
 * Đây là NƠI DUY NHẤT trong toàn bộ Laravel sinh tiến trình bên ngoài.
 * `grep PythonRunner` ra đúng các chỗ bắn, cùng lý do `spendingLlmClient()` là
 * nơi duy nhất cấp quyền tiêu tiền: hành vi nguy hiểm phải đếm được bằng mắt.
 *
 * Đặt ở `app/Services/`, KHÔNG phải `app/Video/` — Architecture Test cấm tên
 * công cụ render trong `app/Video/` (§1), và tầng Video vẫn phải hoàn toàn mù
 * về việc Python tồn tại. Chỉ tầng Service biết.
 *
 * KHÔNG CHỜ, và không thể chờ: `session_runner` chạy vài giây, nhưng
 * `render_queued_shots` chạy 6-18 phút. Chờ trong một HTTP request là hỏng
 * request. Đổi lại, không có cách nào biết script chạy có thành công không —
 * bù bằng log ra file (`log_dir`) và bằng việc trạng thái session tự nói lên
 * kết quả (`composing` mãi = chưa ai compose).
 */
class PythonRunner
{
    /**
     * @return bool `true` = ĐÃ BẮN ĐI (không phải "đã chạy xong, thành công").
     *              `false` = chưa bắn được; caller phải hiện đường lui chạy tay.
     */
    public function spawn(string $script, string $sessionCode): bool
    {
        $dir = (string) config('video.runner.runner_dir', '');

        // TẮT có chủ đích: máy chưa cấu hình thì im lặng bỏ qua, KHÔNG thử chạy
        // một đường dẫn không tồn tại rồi ném lỗi khó hiểu. Nếp cũ (chạy tay)
        // vẫn hoạt động nguyên vẹn.
        if ($dir === '') {
            return false;
        }

        $scriptPath = rtrim($dir, '/\\').DIRECTORY_SEPARATOR.$script;

        if (! is_file($scriptPath)) {
            Log::warning('PythonRunner: khong tim thay script', [
                'script' => $scriptPath,
                'session_code' => $sessionCode,
            ]);

            return false;
        }

        $logFile = $this->logFileFor($script, $sessionCode);

        if ($logFile === null) {
            return false;
        }

        try {
            $this->startDetached(
                (string) config('video.runner.python_bin', 'python'),
                $scriptPath,
                $sessionCode,
                $logFile,
                $dir,
            );
        } catch (\Throwable $e) {
            // Bắn hỏng KHÔNG được làm hỏng nút bấm: RenderPlan đã lưu, session
            // đã tồn tại. Người dùng chỉ mất phần tự động, chạy tay vẫn được.
            Log::error('PythonRunner: sinh tien trinh that bai', [
                'script' => $scriptPath,
                'session_code' => $sessionCode,
                'exception' => $e,
            ]);

            return false;
        }

        Log::info('PythonRunner: da ban tien trinh nen', [
            'script' => $script,
            'session_code' => $sessionCode,
            'log' => $logFile,
        ]);

        return true;
    }

    /** Câu lệnh chạy tay tương đương — hiện lên màn hình khi `spawn()` trả false. */
    public function manualCommand(string $script, string $sessionCode): string
    {
        return sprintf('python tools/%s --session=%s', $script, $sessionCode);
    }

    /**
     * Bắn một lệnh Artisan CỤC BỘ ở nền — §18.30. Cùng lớp, cùng cơ chế
     * `start "" /B` / `nohup &` với `spawn()`, chỉ đổi đối tượng bắn từ script
     * Python (chạy từ `runner_dir`) sang lệnh Artisan (chạy từ `base_path()`
     * của chính repo Laravel này). KHÔNG tách thành class riêng: giữ đúng bất
     * biến "grep PythonRunner ra đúng MỌI chỗ sinh tiến trình" — tên class
     * không đổi dù không còn thuần Python, đổi tên sẽ chỉ ích lợi mỹ quan mà
     * phải sửa lại mọi nơi đang tiêm nó.
     *
     * @param  list<string>  $args  Từng phần tử là MỘT flag hoàn chỉnh
     *                              (`--session=xxx`), tự escape trọn vẹn.
     * @return bool `true` = ĐÃ BẮN ĐI, không phải "đã chạy xong, thành công".
     */
    public function spawnArtisan(string $command, array $args, string $logKey): bool
    {
        // Ten lenh Artisan mang dau `:` (video:build-plan) — ky tu cam trong
        // ten file Windows, phai lam sach truoc khi dung lam ten log.
        $logFile = $this->logFileFor(str_replace(':', '_', $command), $logKey);

        if ($logFile === null) {
            return false;
        }

        try {
            $this->startDetachedArtisan(
                (string) config('video.runner.php_bin', 'php'),
                $command,
                $args,
                $logFile,
            );
        } catch (\Throwable $e) {
            Log::error('PythonRunner: sinh tien trinh artisan that bai', [
                'command' => $command,
                'args' => $args,
                'exception' => $e,
            ]);

            return false;
        }

        Log::info('PythonRunner: da ban artisan nen', [
            'command' => $command,
            'args' => $args,
            'log' => $logFile,
        ]);

        return true;
    }

    /** Câu lệnh chạy tay tương đương cho lệnh Artisan — hiện khi `spawnArtisan()` trả false. */
    public function manualArtisanCommand(string $command, array $args): string
    {
        return trim(sprintf('php artisan %s %s', $command, implode(' ', $args)));
    }

    /**
     * Chạy VÀ CHỜ, trả nguyên văn stdout+stderr. Chỉ dùng cho lượt KHÔNG tiêu tiền.
     *
     * Vì sao không dùng `spawn()`: nó cố ý không chờ, vì render thật mất 6-18
     * phút. Lượt thử thì ngược lại — nó tồn tại để NGƯỜI ĐỌC KẾT QUẢ, mà kết quả
     * bắn vào file log rồi bảo người dùng đi mở log là làm hỏng chính mục đích
     * của nút. Chạy thử không gọi vendor nên xong trong ~1 giây.
     *
     * `$args` thay cho `--session=` cứng: lượt thử truyền `--preflight-file`, một
     * đường dẫn — không phải mã session.
     *
     * @param  list<string>  $args
     * @return array{0: bool, 1: string} [chạy được?, output]
     */
    public function runAndWait(string $script, array $args, int $timeoutSeconds = 120): array
    {
        $dir = (string) config('video.runner.runner_dir', '');

        if ($dir === '') {
            return [false, 'VIDEO_RUNNER_DIR chua cau hinh — khong chay duoc.'];
        }

        $scriptPath = rtrim($dir, '/\\').DIRECTORY_SEPARATOR.$script;

        if (! is_file($scriptPath)) {
            return [false, "Khong tim thay script: {$scriptPath}"];
        }

        $command = array_merge(
            [(string) config('video.runner.python_bin', 'python'), $scriptPath],
            $args,
        );

        try {
            $process = new \Symfony\Component\Process\Process($command, $dir, null, null, $timeoutSeconds);
            $process->run();
        } catch (\Throwable $e) {
            Log::error('PythonRunner: chay dong bo that bai', [
                'script' => $script, 'exception' => $e,
            ]);

            return [false, 'Chay that bai: '.$e->getMessage()];
        }

        // KHÔNG dựa vào exit code để quyết "có kết quả hay không": script thử in
        // ra chẩn đoán hữu ích rồi vẫn có thể thoát khác 0. Output mới là thứ
        // người dùng cần, kể cả khi nó là một traceback.
        return [true, trim($process->getOutput()."\n".$process->getErrorOutput())];
    }

    /** Thư mục log không tạo được thì KHÔNG bắn — chạy mù còn tệ hơn không chạy. */
    private function logFileFor(string $script, string $sessionCode): ?string
    {
        $logDir = (string) config('video.runner.log_dir');

        if (! is_dir($logDir) && ! @mkdir($logDir, 0775, true) && ! is_dir($logDir)) {
            Log::warning('PythonRunner: khong tao duoc thu muc log', ['dir' => $logDir]);

            return null;
        }

        return $logDir.DIRECTORY_SEPARATOR.sprintf(
            '%s_%s_%s.log',
            $sessionCode,
            pathinfo($script, PATHINFO_FILENAME),
            now()->format('Ymd_His'),
        );
    }

    /**
     * Windows và POSIX tách tiến trình theo hai cách hoàn toàn khác nhau —
     * không có cú pháp chung.
     *
     * Windows: `start /B` tách khỏi cửa sổ hiện tại. Tham số rỗng `""` sau
     * `start` là BẮT BUỘC — nếu không, `start` sẽ hiểu chuỗi có dấu ngoặc kép
     * đầu tiên là TIÊU ĐỀ CỬA SỔ chứ không phải chương trình cần chạy, và
     * lệnh im lặng không làm gì. Đây là lỗi kinh điển, rất khó đoán ra.
     *
     * POSIX: `&` đẩy xuống nền, `nohup` để tiến trình sống tiếp khi PHP-FPM
     * đóng phiên.
     */
    private function startDetached(
        string $pythonBin,
        string $scriptPath,
        string $sessionCode,
        string $logFile,
        string $workingDir,
    ): void {
        $command = $this->buildCommand($pythonBin, $scriptPath, $sessionCode, $logFile, $workingDir);

        if ($this->isWindows()) {
            pclose(popen($command, 'r'));

            return;
        }

        exec($command);
    }

    /** @param  list<string>  $args */
    private function startDetachedArtisan(string $phpBin, string $command, array $args, string $logFile): void
    {
        $shellCommand = $this->buildArtisanCommand($phpBin, $command, $args, $logFile);

        if ($this->isWindows()) {
            pclose(popen($shellCommand, 'r'));

            return;
        }

        exec($shellCommand);
    }

    /**
     * Dựng chuỗi lệnh cho `spawnArtisan()` — TÁCH khỏi việc chạy để test
     * được, cùng lý do với `buildCommand()` bên dưới. Chạy từ `base_path()`
     * của chính repo Laravel này, KHÔNG phải `runner_dir` (đó là thư mục repo
     * Python — lệnh Artisan không liên quan tới nó).
     *
     * @param  list<string>  $args  Mỗi phần tử escape TRỌN VẸN như một token —
     *                              khác `buildCommand()` chỉ escape phần giá
     *                              trị rồi nối `--session=` làm tiền tố trần.
     *                              Cả hai cách đều ra một token shell hợp lệ;
     *                              cách này đơn giản hơn khi có NHIỀU flag.
     */
    private function buildArtisanCommand(string $phpBin, string $command, array $args, string $logFile): string
    {
        $workingDir = base_path();
        $argsString = implode(' ', array_map('escapeshellarg', $args));

        if (! $this->isWindows()) {
            return sprintf(
                'cd %s && nohup %s artisan %s %s > %s 2>&1 &',
                escapeshellarg($workingDir),
                escapeshellarg($phpBin),
                escapeshellarg($command),
                $argsString,
                escapeshellarg($logFile),
            );
        }

        return sprintf(
            'cmd /c cd /d %s && start "" /B %s artisan %s %s > %s 2>&1',
            escapeshellarg($this->toWindowsPath($workingDir)),
            escapeshellarg($this->toWindowsPath($phpBin)),
            escapeshellarg($command),
            $argsString,
            escapeshellarg($this->toWindowsPath($logFile)),
        );
    }

    /**
     * Dựng chuỗi lệnh — TÁCH khỏi việc chạy để test được.
     *
     * Tách ra không phải cho đẹp: `start "" /B` là chỗ dễ sai nhất trong cả
     * class (xem docblock bên dưới), mà gộp chung với `popen()` thì không có
     * cách nào kiểm ngoài việc sinh tiến trình thật. Giờ test đọc được chuỗi
     * mà không chạy gì.
     *
     * Windows: `start /B` tách khỏi cửa sổ hiện tại. Tham số rỗng `""` ngay sau
     * `start` là BẮT BUỘC — thiếu nó, `start` hiểu chuỗi trong nháy kép đầu
     * tiên là TIÊU ĐỀ CỬA SỔ chứ không phải chương trình, và lệnh im lặng
     * không làm gì. `cd /d` vì script Python tự tính đường dẫn tương đối theo
     * thư mục gốc repo của nó.
     *
     * POSIX: `&` đẩy xuống nền, `nohup` để tiến trình sống tiếp khi PHP-FPM
     * đóng phiên.
     */
    private function buildCommand(
        string $pythonBin,
        string $scriptPath,
        string $sessionCode,
        string $logFile,
        string $workingDir,
    ): string {
        if (! $this->isWindows()) {
            return sprintf(
                'cd %s && nohup %s %s --session=%s > %s 2>&1 &',
                escapeshellarg($workingDir),
                escapeshellarg($pythonBin),
                escapeshellarg($scriptPath),
                escapeshellarg($sessionCode),
                escapeshellarg($logFile),
            );
        }

        // Normalize TRƯỚC escape, và trên TỪNG thành phần — không phải trên cả
        // chuỗi đã escape. Đổi sau khi escape là sai: `escapeshellarg()` bọc
        // chuỗi trong `"`, nên một đường dẫn kết thúc bằng `\` sẽ thành `"D:\x\"`
        // — mà `\"` là dấu nháy ĐƯỢC ESCAPE, cmd nuốt luôn dấu đóng và parse
        // sai toàn bộ phần còn lại của lệnh.
        //
        // Config buộc dùng `/` vì `.env` xử lý escape trong chuỗi nháy kép:
        // `"D:\1. Work\..."` làm hỏng parser và Laravel KHÔNG BOOT ĐƯỢC (gặp
        // thật 2026-07-31). PHP thì `/` chạy tốt ở mọi hàm file; chỉ cmd.exe
        // mới cần `\`, nên quy đổi đúng tại ranh giới sang shell.
        return sprintf(
            'cmd /c cd /d %s && start "" /B %s %s --session=%s > %s 2>&1',
            escapeshellarg($this->toWindowsPath($workingDir)),
            escapeshellarg($this->toWindowsPath($pythonBin)),
            escapeshellarg($this->toWindowsPath($scriptPath)),
            escapeshellarg($sessionCode),   // KHÔNG normalize: đây là mã, không phải đường dẫn
            escapeshellarg($this->toWindowsPath($logFile)),
        );
    }

    /** Bỏ `\` thừa ở cuối — nguồn của lỗi `"D:\x\"` mô tả ở trên. */
    private function toWindowsPath(string $path): string
    {
        return rtrim(str_replace('/', '\\', $path), '\\');
    }

    private function isWindows(): bool
    {
        return strncasecmp(PHP_OS_FAMILY, 'Windows', 7) === 0;
    }
}
