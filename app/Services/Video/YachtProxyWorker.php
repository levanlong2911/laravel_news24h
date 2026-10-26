<?php

declare(strict_types=1);

namespace App\Services\Video;

use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Chay worker Node cua proxy (scripts/proxy/worker.mjs) voi mot job JSON. Worker in ra MOT dong
 * JSON; job loi, het gio hoac ket qua khong doc duoc -> RuntimeException. Khong giu transaction
 * DB trong luc chay.
 */
final class YachtProxyWorker
{
    /**
     * @param  array<string, mixed>  $job
     * @return array<string, mixed>
     */
    public function run(array $job): array
    {
        $config = (array) config('video.superyacht.proxy');
        $dir = storage_path('app/proxy-jobs');

        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException('Không tạo được thư mục job proxy.');
        }

        $file = $dir.DIRECTORY_SEPARATOR.Str::uuid().'.json';
        file_put_contents($file, json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));

        try {
            $process = new Process([(string) $config['node_binary'], base_path((string) $config['worker']), $file], base_path());
            $process->setTimeout((float) $config['worker_timeout']);
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new RuntimeException('Worker proxy chạy quá '.$config['worker_timeout'].' giây.');
        } finally {
            @unlink($file);
        }

        $lines = array_values(array_filter(explode("\n", trim($process->getOutput()))));

        try {
            $result = json_decode((string) end($lines), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Worker proxy không trả JSON (mã '.$process->getExitCode().'): '.Str::limit(trim($process->getErrorOutput() ?: $process->getOutput()), 300));
        }

        if (! is_array($result) || ($result['ok'] ?? false) !== true) {
            throw new RuntimeException('Worker proxy lỗi: '.(is_array($result) ? (string) ($result['error'] ?? 'không rõ') : 'không rõ'));
        }

        return $result;
    }

    /** Hash cac file bo dung/worker: doi code thi revision cu khong con khop. */
    public static function builderSha256(): string
    {
        $parts = [];

        foreach ((array) config('video.superyacht.proxy.builder_files') as $path) {
            $parts[$path] = (string) hash_file('sha256', base_path((string) $path));
        }

        return hash('sha256', (string) json_encode($parts, JSON_UNESCAPED_SLASHES));
    }
}
