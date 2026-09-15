<?php

namespace App\Video\Media;

use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

/**
 * Do that thong so cua file video bang ffprobe.
 *
 * Duration nguoi dung chon KHONG duoc dung lam bang chung file hop le — no chi la
 * ky vong de doi chieu. Ca duration, width va height deu lay tu day.
 */
final class Mp4Probe
{
    public function __construct(
        private readonly string $binary,
        private readonly int $timeoutSeconds = 20,
    ) {}

    public function available(): bool
    {
        return $this->problem() === null;
    }

    /**
     * Ly do KHONG chay duoc, hoac `null` neu chay duoc.
     *
     * Tra ve ly do chu khong chi true/false, vi loi hay gap nhat khong phai "may
     * thieu ffmpeg" ma la "tien trinh web co PATH khac shell": go `ffprobe` trong
     * terminal thi thay, ma PHP thi khong. Mot cau "khong chay duoc" khong du de
     * ai do biet phai lam gi tiep.
     */
    public function problem(): ?string
    {
        $process = new Process([$this->binary, '-version']);
        $process->setTimeout($this->timeoutSeconds);

        try {
            $process->run();
        } catch (ExceptionInterface $e) {
            return $this->hint(Str::limit($e->getMessage(), 160));
        }

        if (! $process->isSuccessful()) {
            return $this->hint(Str::limit($process->getErrorOutput(), 160));
        }

        return null;
    }

    private function hint(string $detail): string
    {
        $named = str_contains($this->binary, DIRECTORY_SEPARATOR) || str_contains($this->binary, '/');

        return sprintf(
            'khong chay duoc "%s" (%s).%s',
            $this->binary,
            $detail !== '' ? $detail : 'khong ro nguyen nhan',
            $named
                ? ' Kiem lai duong dan trong VIDEO_FFPROBE_BIN.'
                : ' Tien trinh web co the co PATH khac terminal — dat VIDEO_FFPROBE_BIN'
                    .' bang duong dan tuyet doi toi ffprobe roi khoi dong lai may chu.',
        );
    }

    /** @return array{ok: bool, duration_ms: ?int, width: ?int, height: ?int, error: ?string} */
    public function inspect(string $file): array
    {
        $process = new Process([
            $this->binary, '-v', 'error', '-print_format', 'json',
            '-show_entries', 'format=duration:stream=width,height,codec_type', $file,
        ]);
        $process->setTimeout($this->timeoutSeconds);

        try {
            $process->run();
        } catch (ExceptionInterface $e) {
            return $this->fail('khong chay duoc ffprobe: '.Str::limit($e->getMessage(), 200));
        }

        if (! $process->isSuccessful()) {
            return $this->fail('ffprobe that bai: '.Str::limit($process->getErrorOutput(), 200));
        }

        $json = json_decode($process->getOutput(), true);

        if (! is_array($json)) {
            return $this->fail('ffprobe tra ve khong phai JSON');
        }

        $raw = $json['format']['duration'] ?? null;
        $seconds = is_numeric($raw) ? (float) $raw : null;

        if ($seconds === null || ! is_finite($seconds) || $seconds <= 0) {
            return $this->fail('ffprobe khong doc duoc duration hop le');
        }

        foreach ((array) ($json['streams'] ?? []) as $stream) {
            if (! is_array($stream) || ($stream['codec_type'] ?? null) !== 'video') {
                continue;
            }

            $width = (int) ($stream['width'] ?? 0);
            $height = (int) ($stream['height'] ?? 0);

            if ($width <= 0 || $height <= 0) {
                return $this->fail('luong video khong co kich thuoc hop le');
            }

            return [
                'ok' => true,
                'duration_ms' => (int) round($seconds * 1000),
                'width' => $width,
                'height' => $height,
                'error' => null,
            ];
        }

        return $this->fail('file khong co luong video nao');
    }

    /** @return array{ok: bool, duration_ms: ?int, width: ?int, height: ?int, error: ?string} */
    private function fail(string $message): array
    {
        return ['ok' => false, 'duration_ms' => null, 'width' => null, 'height' => null, 'error' => $message];
    }
}
