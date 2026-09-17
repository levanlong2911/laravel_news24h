<?php

namespace App\Video\Media;

/**
 * Doc con so khung hinh ma ffprobe tra ve.
 *
 * Tach ra thanh mot lop rieng chu khong phai mot method public cua
 * `VideoFrameCounter`: o do no se la be mat production duoc noi rong chi vi nhu cau
 * test. O day, doc va phan xet mot gia tri LA toan bo viec cua lop, nen public la
 * dung nghia — va luat van kiem duoc.
 */
final class FrameCountParser
{
    /**
     * CHI nhan so nguyen duong, khong dung `is_numeric()`.
     *
     * ffprobe viet "N/A" khi no khong dem duoc, va `(int) 'N/A'` ra 0. Voi
     * `is_numeric()` thi `'0'`, `'0.5'` va `'1e3'` deu lot — roi mot output RONG dem
     * duoc 0 khung se "khop" voi mot input cung dem duoc 0, va phep kiem integrity di
     * qua ma khong thay gi. Mot luong video that luon co it nhat mot khung.
     */
    public static function parse(mixed $raw, string $diagnostics = ''): VideoFrameCountResult
    {
        if (! is_string($raw) && ! is_int($raw)) {
            return VideoFrameCountResult::failed('ffprobe khong dem duoc khung hinh', $diagnostics);
        }

        if (preg_match('/^[1-9][0-9]*$/', (string) $raw) !== 1) {
            return VideoFrameCountResult::failed(
                'ffprobe tra ve so khung khong dung: '.var_export($raw, true),
                $diagnostics,
            );
        }

        return VideoFrameCountResult::counted((int) $raw, $diagnostics);
    }
}
