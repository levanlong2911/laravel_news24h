<?php

namespace App\Video\FinalComposition;

/**
 * Bam sha256 theo KHOI, kiem ngan sach thoi gian giua cac khoi.
 *
 * `hash_file()` tren mot file hang tram MB chay toi cung roi moi tra ve: deadline
 * dat truoc do chi phat hien het gio SAU khi da tieu het thoi gian, tuc la khong
 * ngan duoc gi. Vong nay dung ngay giua chung.
 */
final class ChunkedDigest
{
    private const CHUNK_BYTES = 1 << 20;

    /**
     * @param  string  $stage  ten buoc, de thong bao het gio noi ro dang bam cai gi
     * @return string|null `null` khi khong doc duoc file
     *
     * @throws CompositionRefused khi het ngan sach giua chung
     */
    public static function sha256(string $path, Deadline $deadline, string $stage): ?string
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        $context = hash_init('sha256');

        try {
            while (! feof($handle)) {
                $chunk = fread($handle, self::CHUNK_BYTES);

                if ($chunk === false) {
                    return null;
                }

                if ($chunk !== '') {
                    hash_update($context, $chunk);
                }

                if ($deadline->expired()) {
                    throw new CompositionRefused('het ngan sach thoi gian khi dang bam '.$stage);
                }
            }
        } finally {
            fclose($handle);
        }

        return hash_final($context);
    }
}
