<?php

namespace App\Video\FinalComposition;

use App\Video\Media\FfmpegRunner;
use App\Video\Media\FrameCounter;
use App\Video\Media\MediaProbe;
use Illuminate\Support\Str;

/**
 * File ra co dung la thu da dat hay khong.
 *
 * ffmpeg tra 0 KHONG co nghia file dung duoc: no co the sinh file rong, mat audio,
 * doi codec, hoac dung giua chung ma van thoat sach. Do lai la cach duy nhat biet.
 */
final class CompositionOutputVerifier
{
    public function __construct(
        private readonly MediaProbe $probe,
        private readonly FrameCounter $frames,
        private readonly FfmpegRunner $ffmpeg,
        private readonly int $audioToleranceSamples,
        private readonly int $timingToleranceMs,
    ) {}

    /**
     * @return array{reasons: list<string>, frames: ?int, samples: ?int}
     *         `reasons` rong la dat
     */
    public function inspect(
        CompositionExpectation $expected,
        string $output,
        string $pcmPath,
        Deadline $deadline,
    ): array {
        $p = $expected->profile;
        $measured = $this->probe->inspect($output, $deadline->remaining());

        if (($measured['ok'] ?? false) !== true) {
            return $this->fail(['output khong doc duoc: '.($measured['error'] ?? 'khong ro')]);
        }

        if (($measured['video_count'] ?? 0) !== 1 || ($measured['audio_count'] ?? 0) !== 1) {
            return $this->fail([sprintf(
                'output phai co dung mot luong hinh va mot luong tieng, do duoc %d/%d',
                $measured['video_count'] ?? 0, $measured['audio_count'] ?? 0,
            )]);
        }

        // `pix_fmt`, `codec_name`, `r_frame_rate`, `sample_aspect_ratio` nam TRONG
        // `video`, khong o goc cua mang probe.
        $reasons = [
            ...$this->compare('video', (array) $measured['video'], [
                'codec_name' => 'h264',
                'pix_fmt' => 'yuv420p',
                'r_frame_rate' => $p->fps.'/1',
                'sample_aspect_ratio' => '1:1',
            ]),
            ...$this->compare('audio', (array) $measured['audio'], [
                'codec_name' => 'aac',
                'sample_rate' => (string) $p->sampleRate,
                'channels' => (string) $p->channels,
            ]),
        ];

        if ((int) ($measured['width'] ?? 0) !== $p->width || (int) ($measured['height'] ?? 0) !== $p->height) {
            $reasons[] = sprintf(
                'kich thuoc: cho %dx%d, do duoc %sx%s',
                $p->width, $p->height, $measured['width'] ?? '?', $measured['height'] ?? '?',
            );
        }

        // Hai luong phai bat dau CUNG mot cho, VA dai dung bang timeline.
        //
        // Phep dem mau o duoi khong thay duoc hai dieu nay: mot ban phim tre tieng
        // 200 ms van co dung so mau, va mot ban thieu vai tram mau van don len cung
        // mot boi cua 1024. Kiem duration o day CHAT HON phep dem 64 lan.
        $reasons = [...$reasons, ...$this->timing(
            $expected, (array) $measured['video'], (array) $measured['audio'],
        )];

        // Profile sai thi DUNG o day: giai ma ca luong tieng cua mot file da hong
        // profile la tieu thoi gian cho mot con so khong dung de lam gi.
        if ($reasons !== []) {
            return $this->fail($reasons);
        }

        $count = $this->frames->count($output, $deadline->remaining());

        if (! $count->ok) {
            return $this->fail(['khong dem duoc khung: '.$count->error]);
        }

        // Dem DU khung khong chung minh doc SACH: mot khung giai ma loi van la mot
        // khung. ffprobe co the thoat 0 ma van in loi ra stderr.
        if (trim($count->diagnostics) !== '') {
            $reasons[] = 'ffprobe bao loi khi giai ma output: '.Str::limit($count->diagnostics, 300);
        }

        // Khung hinh: BANG TUYET DOI. No roi rac, khong co gi de dung sai.
        if ($count->frames !== $expected->frames) {
            $reasons[] = sprintf('so khung: cho %d, do duoc %d', $expected->frames, $count->frames);
        }

        [$samples, $audioReasons] = $this->samples($expected, $output, $pcmPath, $deadline);

        return [
            'reasons' => [...$reasons, ...$audioReasons],
            'frames' => $count->frames,
            'samples' => $samples,
        ];
    }

    /**
     * Do tieng bang cach GIAI MA ra PCM roi dem mau.
     *
     * Khong ep `-ac`/`-ar` o lenh giai ma: ep la tu chuyen mot output sai kenh ve
     * dung truoc khi dem, tuc la tu xoa bang chung. Profile da duoc kiem o tren.
     *
     * @return array{0: ?int, 1: list<string>}
     */
    private function samples(
        CompositionExpectation $expected,
        string $output,
        string $pcmPath,
        Deadline $deadline,
    ): array {
        // `-v error` de stderr chi chua muc LOI: khong co no thi ffmpeg do day thong
        // tin binh thuong ra stderr va khong the phan biet loi voi tieng on.
        $run = $this->ffmpeg->run([
            '-hide_banner', '-nostdin', '-v', 'error', '-n', '-i', $output,
            '-map', '0:a:0', '-f', 's16le', $pcmPath,
        ], $deadline->remaining());

        if (! $run->successful) {
            return [null, ['khong giai ma duoc luong tieng: '.$run->tail(200)]];
        }

        // Thoat 0 KHONG co nghia doc sach — dung ly le da dung cho duong hinh.
        if (trim($run->stderr) !== '') {
            return [null, ['ffmpeg bao loi khi giai ma tieng: '.Str::limit(trim($run->stderr), 300)]];
        }

        $bytes = @filesize($pcmPath);

        if ($bytes === false) {
            return [null, ['khong doc duoc kich thuoc file PCM']];
        }

        $frameBytes = 2 * $expected->profile->channels;

        if ($bytes % $frameBytes !== 0) {
            return [null, [sprintf(
                'PCM %d byte khong chia het cho %d — luong tieng khong nguyen ven', $bytes, $frameBytes,
            )]];
        }

        $samples = intdiv($bytes, $frameBytes);

        // AAC ma hoa theo KHOI 1024 mau, nen luong giai ma ra luon duoc dem len mot
        // so nguyen khoi. Do that sau lan, ca sau deu khop chinh xac cong thuc nay:
        //
        //   timeline   don len khoi    do duoc
        //    384000      384000         384000     (1 clip)
        //    768000      768000         768000     (cat thang)
        //    640000      640000         640000     (CO crossfade, chia chan 1024)
        //    744000      744448         744448
        //   1104000     1104896        1104896
        //    552000      552960         552960
        //
        // Ca thu ba la ca phan dinh: co crossfade ma lech 0. Truoc do toi tuong do
        // lech cong don theo so moi noi mo — no khong phai vay, chi la trung hop ba
        // con so dau khong chia chan 1024.
        //
        // GIOI HAN CUA PHEP NAY, phai noi ro: dong len khoi lam MAT kha nang phan
        // biet trong pham vi mot khoi. Mot timeline thieu 100 mau cung don len dung
        // con so ay. Nen day la mot kiem tra BO SUNG — no bat duoc thieu/thua tu mot
        // khoi tro len (21,3 ms), khong phai bang chung rang tieng dai dung tung mau.
        // Muon chinh xac tung mau thi phai do TRUOC khi ma hoa AAC, va do la mot luot
        // chay graph nua; chua lam o muc nay.
        $frame = 1024;
        $timeline = $expected->audioSamples;
        $rounded = (int) ceil($timeline / $frame) * $frame;
        $drift = abs($samples - $rounded);

        if ($drift > $this->audioToleranceSamples) {
            return [$samples, [sprintf(
                'tieng lech %d mau (%.3f ms): timeline %d, don len khoi AAC thanh %d, do duoc %d',
                $drift, $drift * 1000 / $expected->profile->sampleRate, $timeline, $rounded, $samples,
            )]];
        }

        return [$samples, []];
    }

    /**
     * Moc bat dau va do dai cua hai luong, doi chieu voi timeline.
     *
     * Nguong den TU PHEP DO, khong tu mot uoc luong ve dinh dang. Sau ban ghep that:
     *
     *   khung   timeline     hinh         tieng        lech tieng
     *    320    13.333333    13.333333    13.333000    -0.333 ms
     *    276    11.500000    11.500000    11.500000    +0.000 ms
     *    552    23.000000    23.000000    23.000000    +0.000 ms
     *    384    16.000000    16.000000    16.000000    +0.000 ms
     *    192     8.000000     8.000000     8.000000    +0.000 ms
     *
     * Hinh khop tuyet doi ca sau lan; tieng lech nhieu nhat 0,333 ms. Nguong mac
     * dinh 5 ms la khoang 15 lan so do lon nhat — va van CHAT HON diem mu 21,3 ms
     * cua phep dem mau, nen no khep duoc phan lon khoang trong do.
     *
     * @param  array<string, mixed>  $video
     * @param  array<string, mixed>  $audio
     * @return list<string>
     */
    private function timing(CompositionExpectation $expected, array $video, array $audio): array
    {
        $timeline = $expected->frames / $expected->profile->fps;
        $reasons = [];

        foreach ([['hinh', $video], ['tieng', $audio]] as [$label, $stream]) {
            $start = $stream['start_time'] ?? null;
            $duration = $stream['duration'] ?? null;

            if (! is_float($start) && ! is_int($start)) {
                $reasons[] = 'output: luong '.$label.' khong khai bao start_time';

                continue;
            }

            if (! is_float($duration) && ! is_int($duration)) {
                $reasons[] = 'output: luong '.$label.' khong khai bao duration';

                continue;
            }

            // Moc dau phai la 0, khong chi phai bang nhau. Hai luong CUNG bat dau o
            // giay 1 thi chenh lech bang 0 va duration van dung — nhung output cua
            // muc nay da chuan hoa ve timeline bat dau tu 0, nen do la mot ban phim
            // co mot giay dau khong ai dat.
            if (abs($start) * 1000 > $this->timingToleranceMs) {
                $reasons[] = sprintf(
                    'output: luong %s bat dau o %.6f s, phai la 0 (dung sai %d ms)',
                    $label, $start, $this->timingToleranceMs,
                );
            }

            if (abs($duration - $timeline) * 1000 > $this->timingToleranceMs) {
                $reasons[] = sprintf(
                    'output: luong %s dai %.6f s, timeline %.6f s (lech %.3f ms, dung sai %d ms)',
                    $label, $duration, $timeline, ($duration - $timeline) * 1000, $this->timingToleranceMs,
                );
            }
        }

        $videoStart = $video['start_time'] ?? null;
        $audioStart = $audio['start_time'] ?? null;

        if ((is_float($videoStart) || is_int($videoStart)) && (is_float($audioStart) || is_int($audioStart))) {
            $gapMs = abs($audioStart - $videoStart) * 1000;

            if ($gapMs > $this->timingToleranceMs) {
                $reasons[] = sprintf(
                    'output: tieng bat dau lech hinh %.3f ms (hinh %.6f, tieng %.6f)',
                    $gapMs, $videoStart, $audioStart,
                );
            }
        }

        return $reasons;
    }

    /**
     * @param  array<string, mixed>  $measured
     * @param  array<string, string>  $expected
     * @return list<string>
     */
    private function compare(string $label, array $measured, array $expected): array
    {
        $reasons = [];

        foreach ($expected as $field => $want) {
            if ((string) ($measured[$field] ?? '') !== $want) {
                $reasons[] = sprintf(
                    '%s.%s: cho %s, do duoc %s', $label, $field, $want, $measured[$field] ?? '(khong co)',
                );
            }
        }

        return $reasons;
    }

    /**
     * @param  list<string>  $reasons
     * @return array{reasons: list<string>, frames: ?int, samples: ?int}
     */
    private function fail(array $reasons): array
    {
        return ['reasons' => $reasons, 'frames' => null, 'samples' => null];
    }
}
