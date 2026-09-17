<?php

namespace App\Video\Media;

use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Ghep nhieu clip thanh mot, CHI bang `-c copy`.
 *
 * Khong tu re-encode khi thong so lech. Re-encode la mat chat luong va ton thoi
 * gian — do la quyet dinh cua nguoi dung, khong phai mac dinh lang le cua mot ham.
 *
 * Lop nay chi DIEU PHOI: probe → so → goi runner → do lai. Chinh sach o
 * `StreamCompatibility`, thao tac o `FfmpegRunner`, dem khung o `VideoFrameCounter`.
 * No khong biet gi ve `VideoRender`, khong doc DB, va nhan duong dan da duoc nguoi
 * goi xac thuc.
 */
final class ClipConcatenator
{
    public const NOT_ENOUGH_INPUT = 'not_enough_input';

    public const OUTPUT_ALREADY_EXISTS = 'output_already_exists';

    public const FFMPEG_FAILED = 'ffmpeg_failed';

    public const OUTPUT_UNUSABLE = 'output_unusable';

    public const OUTPUT_DRIFTED = 'output_duration_drifted';

    public const FRAME_LOSS = 'output_frame_loss';

    public const FRAME_COUNT_UNREADABLE = 'frame_count_unreadable';

    public const COMPOSITION_FAILED = 'composition_failed';

    public function __construct(
        private readonly MediaProbe $probe,
        private readonly FfmpegRunner $ffmpeg,
        private readonly StreamCompatibility $compatibility,
        private readonly VideoFrameCounter $frames,
        private readonly string $tempRoot,
        private readonly int $durationToleranceMs,
    ) {}

    /** @param list<string> $files duong dan tuyet doi, nguoi goi DA xac thuc */
    public function concat(array $files, string $output): ClipConcatenationResult
    {
        // TU CHOI TRUOC KHI LAM BAT KY VIEC GI.
        //
        // Duoi kia co mot buoc xoa `$output` khi that bai — no ton tai de khong de
        // lai file nua voi. Nhung neu file do la CUA NGUOI GOI, co tu truoc, thi cai
        // xoa ay huy mot thu ta khong he tao ra. Lop nay la generic; no khong duoc
        // dua vao viec lenh goi no da kiem ho.
        if (is_file($output)) {
            return ClipConcatenationResult::refused(
                self::OUTPUT_ALREADY_EXISTS, ['output da ton tai: '.$output],
            );
        }

        $tempDir = null;

        try {
            $tempDir = $this->makeTempDir();
            $result = $this->compose(array_values($files), $output, $tempDir);
        } catch (Throwable $e) {
            // Khong phan loai duoc: KHONG doan bua no thuoc ve ffmpeg hay ve output.
            $result = ClipConcatenationResult::refused(
                self::COMPOSITION_FAILED, [Str::limit($e->getMessage(), 400)],
            );
        } finally {
            if ($tempDir !== null) {
                $this->removeDirectory($tempDir);
            }
        }

        // Khong de lai file nua voi: no se bi tuong nham la ket qua dung duoc.
        if (! $result->successful && is_file($output)) {
            @unlink($output);
        }

        return $result;
    }

    /** @param list<string> $files */
    private function compose(array $files, string $output, string $tempDir): ClipConcatenationResult
    {
        if (count($files) < 2) {
            return ClipConcatenationResult::refused(
                self::NOT_ENOUGH_INPUT, ['can it nhat hai file de ghep'],
            );
        }

        $probes = [];
        $expectedMs = 0;
        $expectedFrames = 0;

        foreach ($files as $i => $file) {
            $label = 'clip '.($i + 1);
            $probe = $this->probe->inspect($file);
            $verdict = $this->compatibility->unusable($probe, $label);

            if (! $verdict->accepted) {
                return ClipConcatenationResult::refused($verdict->code, $verdict->reasons);
            }

            $count = $this->frames->count($file);

            if (! $count->ok) {
                // KHONG quay ve dung duration thay the: duration bam theo audio, nen
                // no khong noi duoc gi ve khung hinh.
                return ClipConcatenationResult::refused(
                    self::FRAME_COUNT_UNREADABLE, [$label.': '.(string) $count->error],
                );
            }

            $probes[] = $probe;
            $expectedMs += (int) $probe['duration_ms'];
            $expectedFrames += (int) $count->frames;
        }

        foreach ($probes as $i => $probe) {
            if ($i === 0) {
                continue;
            }

            $verdict = $this->compatibility->differences(
                $probes[0], $probe, 'clip 1', 'clip '.($i + 1),
            );

            if (! $verdict->accepted) {
                // TU CHOI TRUOC KHI CHAM TOI FFMPEG. Test khang dinh dieu nay bang
                // mot `FfmpegRunner` gia dem so lan duoc goi — phai la 0.
                return ClipConcatenationResult::refused($verdict->code, $verdict->reasons);
            }
        }

        $listFile = $this->writeList($files, $tempDir);

        try {
            $run = $this->ffmpeg->run([
                '-hide_banner', '-nostdin', '-y',
                '-f', 'concat', '-safe', '0', '-i', $listFile,
                // Map TUONG MINH: bo cuc output do lenh nay khang dinh, khong do mac
                // dinh cua ffmpeg. `?` cho truong hop khong co audio.
                '-map', '0:v:0', '-map', '0:a:0?',
                '-c', 'copy', '-movflags', '+faststart', $output,
            ]);
        } catch (Throwable $e) {
            return ClipConcatenationResult::refused(
                self::FFMPEG_FAILED, [Str::limit($e->getMessage(), 400)],
            );
        }

        if (! $run->successful) {
            return ClipConcatenationResult::refused(self::FFMPEG_FAILED, [$run->tail()]);
        }

        try {
            return $this->verify($output, $probes[0], $expectedMs, $expectedFrames);
        } catch (Throwable $e) {
            return ClipConcatenationResult::refused(
                self::OUTPUT_UNUSABLE, [Str::limit($e->getMessage(), 400)],
            );
        }
    }

    /**
     * ffmpeg tra 0 KHONG co nghia file dung duoc: no co the sinh file rong, mat
     * audio, hoac am tham doi codec. Do lai la cach duy nhat biet.
     *
     * @param  array<string, mixed>  $baseline
     */
    private function verify(
        string $output,
        array $baseline,
        int $expectedMs,
        int $expectedFrames,
    ): ClipConcatenationResult {
        $probe = $this->probe->inspect($output);
        $verdict = $this->compatibility->unusable($probe, 'output');

        if (! $verdict->accepted) {
            return ClipConcatenationResult::refused(self::OUTPUT_UNUSABLE, $verdict->reasons);
        }

        // Da do that: `-c copy` giu nguyen ca `time_base` lan `extradata_hash`, nen
        // phep so nay khong bao nham. No bat duoc truong hop ffmpeg doi codec hoac
        // roi mat audio ma van tra 0.
        $verdict = $this->compatibility->differences($baseline, $probe, 'clip 1', 'output');

        if (! $verdict->accepted) {
            return ClipConcatenationResult::refused(self::OUTPUT_UNUSABLE, $verdict->reasons);
        }

        $count = $this->frames->count($output);

        if (! $count->ok) {
            return ClipConcatenationResult::refused(
                self::FRAME_COUNT_UNREADABLE, ['output: '.(string) $count->error],
            );
        }

        // Day moi la chot integrity. Duration khong thay duoc mat khung, vi no bam
        // theo audio.
        if ((int) $count->frames !== $expectedFrames) {
            return ClipConcatenationResult::refused(self::FRAME_LOSS, [sprintf(
                'output co %d khung, tong cac phan %d khung',
                $count->frames, $expectedFrames,
            )]);
        }

        $measured = (int) $probe['duration_ms'];

        // Nguong nay CHI kiem troi do dai. No khong noi gi ve khung hinh — do la
        // viec cua phep dem o tren.
        if (abs($measured - $expectedMs) > $this->durationToleranceMs) {
            return ClipConcatenationResult::refused(self::OUTPUT_DRIFTED, [sprintf(
                'do duoc %dms, tong cac phan %dms, dung sai %dms',
                $measured, $expectedMs, $this->durationToleranceMs,
            )]);
        }

        return ClipConcatenationResult::composed(
            expectedDurationMs: $expectedMs,
            durationMs: $measured,
            expectedVideoFrames: $expectedFrames,
            videoFrames: (int) $count->frames,
            width: (int) $probe['width'],
            height: (int) $probe['height'],
            bytes: (int) filesize($output),
            sha256: (string) hash_file('sha256', $output),
        );
    }

    /** @param list<string> $files */
    private function writeList(array $files, string $tempDir): string
    {
        $listFile = $tempDir.DIRECTORY_SEPARATOR.'inputs.ffconcat';
        $lines = [];

        foreach ($files as $file) {
            // Concat demuxer boc duong dan trong nhay don; mot nhay don trong ten tep
            // phai duoc thoat, neu khong dong do bi cat lam doi.
            $lines[] = "file '".str_replace("'", "'\\''", $file)."'";
        }

        if (file_put_contents($listFile, implode("\n", $lines)."\n") === false) {
            throw new RuntimeException('Khong ghi duoc danh sach ghep: '.$listFile);
        }

        return $listFile;
    }

    /**
     * Thu muc tam RIENG, khong dat canh output.
     *
     * Output preview hom nay nam trong mot thu muc ta kiem soat, nhung output cua
     * duong production sau nay thi khong — va luc do mot file `.ffconcat` nam canh
     * artifact la rac trong kho.
     */
    private function makeTempDir(): string
    {
        $dir = rtrim($this->tempRoot, '/\\').DIRECTORY_SEPARATOR.Str::uuid()->toString();

        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException('Khong tao duoc thu muc tam: '.$dir);
        }

        return $dir;
    }

    private function removeDirectory(string $dir): void
    {
        foreach (glob($dir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($dir);
    }
}
