<?php

namespace App\Video\Media;

use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

/**
 * Cong toi ffmpeg. Chi biet chay binary, khong biet gi ve ghep clip.
 *
 * Duong dan duoc giai theo BA luat, khong phai hai. Du an nay da dinh mot lan canh
 * "cau hinh o shell ma tien trinh web khong thay" voi ffprobe, va hai bien moi
 * truong la hai co hoi lech nhau — nen khi `VIDEO_FFMPEG_BIN` de trong thi suy ra
 * tu chinh duong ffprobe da chay duoc.
 */
final class FfmpegBinary implements FfmpegRunner
{
    public const SOURCE_CONFIGURED = 'VIDEO_FFMPEG_BIN';

    public const SOURCE_DERIVED = 'suy ra tu VIDEO_FFPROBE_BIN';

    public function __construct(
        private readonly string $binary,
        private readonly string $source,
        private readonly int $timeoutSeconds = 600,
    ) {}

    /**
     * Ba luat, dung thu tu:
     *
     *   1. `VIDEO_FFMPEG_BIN` co gia tri  -> dung, uu tien tuyet doi
     *   2. ffprobe la duong dan cu the    -> cung thu muc, doi ten tep
     *   3. ffprobe la ten tran            -> tra ve ten tran `ffmpeg`
     *
     * Luat 3 khong duoc bo qua: lam phau thuat chuoi tren mot ten tran se de ra mot
     * duong dan khong ton tai, roi `problem()` bao sai cho.
     *
     * @return array{0: string, 1: string} [$binary, $source]
     */
    public static function resolve(?string $configured, string $ffprobeBin): array
    {
        $configured = trim((string) $configured);

        if ($configured !== '') {
            return [$configured, self::SOURCE_CONFIGURED];
        }

        $probe = trim($ffprobeBin);
        $slash = max(strrpos($probe, '/'), strrpos($probe, DIRECTORY_SEPARATOR));

        if ($slash === false) {
            return ['ffmpeg', self::SOURCE_DERIVED];
        }

        $directory = substr($probe, 0, $slash + 1);
        $name = substr($probe, $slash + 1);
        $suffix = str_ends_with(strtolower($name), '.exe') ? '.exe' : '';

        return [$directory.'ffmpeg'.$suffix, self::SOURCE_DERIVED];
    }

    public function path(): string
    {
        return $this->binary;
    }

    public function source(): string
    {
        return $this->source;
    }

    public function available(): bool
    {
        return $this->problem() === null;
    }

    public function problem(): ?string
    {
        $result = $this->run(['-version']);

        if ($result->successful) {
            return null;
        }

        return sprintf(
            'khong chay duoc "%s" (%s, %s). Dat VIDEO_FFMPEG_BIN bang duong dan tuyet doi'
            .' toi ffmpeg roi khoi dong lai may chu.',
            $this->binary,
            $this->source,
            $result->tail(160) !== '' ? $result->tail(160) : 'khong ro nguyen nhan',
        );
    }

    /** Dong dau cua `-version`, hoac `null` neu khong chay duoc. */
    public function version(): ?string
    {
        $result = $this->run(['-version']);

        if (! $result->successful) {
            return null;
        }

        return trim(strtok($result->stdout, "\r\n") ?: '') ?: null;
    }

    /**
     * Encoder co mat khong.
     *
     * Chi la THONG TIN, khong phai dieu kien dau: chang nay ghep bang `-c copy`, nen
     * mot ban ffmpeg thieu libx264 van ghep duoc.
     */
    public function hasEncoder(string $name): bool
    {
        $result = $this->run(['-hide_banner', '-encoders']);

        return $result->successful && str_contains($result->stdout, $name);
    }

    /** @param list<string> $args */
    public function run(array $args, ?int $timeoutSeconds = null): FfmpegResult
    {
        // Mang, khong phai chuoi shell: mot duong dan co dau cach hay ky tu la khong
        // duoc phep tro thanh mot tham so khac.
        $process = new Process(array_merge([$this->binary], $args));
        $process->setTimeout($timeoutSeconds ?? $this->timeoutSeconds);

        try {
            $process->run();
        } catch (ExceptionInterface $e) {
            return FfmpegResult::failed(Str::limit($e->getMessage(), 400));
        }

        return new FfmpegResult(
            successful: $process->isSuccessful(),
            exitCode: $process->getExitCode(),
            stdout: $process->getOutput(),
            stderr: $process->getErrorOutput(),
        );
    }
}
