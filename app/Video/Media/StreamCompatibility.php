<?php

namespace App\Video\Media;

/**
 * Chinh sach: hai file co duoc phep ghep bang `-c copy` khong.
 *
 * Toan bo phan xet nam o day, KHONG o `Mp4Probe` — probe con duoc duong clip da tra
 * tien dung, nen mot luat sinh ra cho viec ghep khong duoc lam hong chang checkpoint.
 *
 * Day la CHINH SACH AN TOAN cua du an, khong phai gioi han tuyet doi cua FFmpeg.
 * `time_base` la vi du ro nhat: khac time base chua chac ffmpeg tu choi, nhung ta
 * tu choi truoc — nen ma loi la `stream_metadata_mismatch`, khong phai cau
 * "FFmpeg khong ghep duoc".
 */
final class StreamCompatibility
{
    /** File khong doc duoc, duration hong, hoac khong co luong video nao. */
    public const UNREADABLE = 'probe_unreadable';

    /** Doc duoc, nhung bo cuc luong khong dung cho ghep copy. */
    public const UNSUPPORTED_LAYOUT = 'probe_unsupported_layout';

    /** Doc duoc, bo cuc dung, nhung thieu truong de quyet dinh. */
    public const INCOMPLETE = 'probe_incomplete';

    /** Hai file deu hop le nhung khong khop nhau. */
    public const MISMATCH = 'stream_metadata_mismatch';

    private const VIDEO_REQUIRED = [
        'codec_name', 'profile', 'level', 'pix_fmt',
        'r_frame_rate', 'avg_frame_rate', 'time_base',
        'width', 'height', 'codec_tag_string', 'extradata_hash',
    ];

    private const VIDEO_OPTIONAL = [
        'sample_aspect_ratio', 'color_range', 'color_space',
        'color_transfer', 'color_primaries', 'field_order',
        // Bieu dien THO, khong quy doi: file nay ghi `rotation` la so, file kia ghi
        // `displaymatrix` la ma tran. Hai bieu dien khac nhau bi tu choi — doan xem
        // chung co cung mot phep quay khong la doan, va doan sai thi ra video nguoc.
        'rotation',
    ];

    private const AUDIO_REQUIRED = [
        'codec_name', 'sample_rate', 'channels', 'channel_layout',
        'sample_fmt', 'time_base', 'codec_tag_string', 'extradata_hash',
    ];

    /**
     * `profile` KHONG bat buoc: nhieu codec hop le khong he co khai niem do — PCM
     * chang han. Bat buoc no thi mot luong audio dung bi bao "thieu truong" cho mot
     * thu no khong bao gio co. Van SO: vang ca hai ben thi khop.
     */
    private const AUDIO_OPTIONAL = ['profile'];

    /**
     * Mot file co dung duoc cho ghep copy khong. Chay MOT LAN moi file.
     *
     * Tach khoi `differences()` de loi chi dung nguon: mot file co them luong data la
     * khuyet tat cua RIENG no, khong phai su lech giua hai file — va de file dau
     * khong bi kiem lai N-1 lan voi nhan "baseline vs candidate".
     *
     * @param  array<string, mixed>  $probe ket qua `MediaProbe::inspect()`
     */
    public function unusable(array $probe, string $label): StreamCompatibilityResult
    {
        // Doc duoc hay khong la chuyen KHAC voi bo cuc co dung khong: mot ben bao
        // nguoi chay phai sua file, ben kia bao ho phai chon clip khac.
        if (($probe['ok'] ?? false) !== true) {
            return StreamCompatibilityResult::rejected(self::UNREADABLE, [
                $label.': '.(string) ($probe['error'] ?? 'khong doc duoc'),
            ]);
        }

        $layout = [];

        if ((int) $probe['video_count'] !== 1) {
            $layout[] = sprintf('%s: can dung 1 luong video, co %d', $label, $probe['video_count']);
        }

        if ((int) $probe['audio_count'] > 1) {
            $layout[] = sprintf('%s: can 0 hoac 1 luong audio, co %d', $label, $probe['audio_count']);
        }

        foreach ((array) $probe['streams'] as $stream) {
            if (! in_array($stream['codec_type'], ['video', 'audio'], true)) {
                // Neu ten VA vi tri, khong chi mot con dem: "luong data o index 2"
                // thi chan duoc, "co 1 luong khac" thi khong.
                $layout[] = sprintf(
                    '%s: luong %s o index %d khong duoc nhan',
                    $label, $stream['codec_type'], $stream['index'],
                );
            }
        }

        if ($layout !== []) {
            return StreamCompatibilityResult::rejected(self::UNSUPPORTED_LAYOUT, $layout);
        }

        $missing = [];

        foreach (self::VIDEO_REQUIRED as $field) {
            if (($probe['video'][$field] ?? null) === null) {
                $missing[] = sprintf('%s: video thieu `%s`', $label, $field);
            }
        }

        if (($probe['audio'] ?? null) !== null) {
            foreach (self::AUDIO_REQUIRED as $field) {
                if (($probe['audio'][$field] ?? null) === null) {
                    $missing[] = sprintf('%s: audio thieu `%s`', $label, $field);
                }
            }
        }

        return $missing === []
            ? StreamCompatibilityResult::accepted()
            : StreamCompatibilityResult::rejected(self::INCOMPLETE, $missing);
    }

    /**
     * Hai file DA QUA `unusable()` co ghep copy duoc voi nhau khong.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    public function differences(
        array $a,
        array $b,
        string $labelA,
        string $labelB,
    ): StreamCompatibilityResult {
        $reasons = [];

        foreach ([...self::VIDEO_REQUIRED, ...self::VIDEO_OPTIONAL] as $field) {
            $left = $a['video'][$field] ?? null;
            $right = $b['video'][$field] ?? null;

            if ($left !== $right) {
                $reasons[] = $this->line('video.'.$field, $left, $right, $labelA, $labelB);
            }
        }

        $hasA = ($a['audio'] ?? null) !== null;
        $hasB = ($b['audio'] ?? null) !== null;

        if ($hasA !== $hasB) {
            $reasons[] = sprintf(
                'audio: %s %s, %s %s',
                $labelA, $hasA ? 'co' : 'khong co',
                $labelB, $hasB ? 'co' : 'khong co',
            );
        } elseif ($hasA) {
            foreach ([...self::AUDIO_REQUIRED, ...self::AUDIO_OPTIONAL] as $field) {
                $left = $a['audio'][$field] ?? null;
                $right = $b['audio'][$field] ?? null;

                if ($left !== $right) {
                    $reasons[] = $this->line('audio.'.$field, $left, $right, $labelA, $labelB);
                }
            }
        }

        return $reasons === []
            ? StreamCompatibilityResult::accepted()
            : StreamCompatibilityResult::rejected(self::MISMATCH, $reasons);
    }

    private function line(string $field, mixed $left, mixed $right, string $labelA, string $labelB): string
    {
        return sprintf(
            '%s: %s=%s, %s=%s',
            $field, $labelA, var_export($left, true), $labelB, var_export($right, true),
        );
    }
}
