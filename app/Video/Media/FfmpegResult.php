<?php

namespace App\Video\Media;

/**
 * Ket qua mot lan chay ffmpeg.
 *
 * La mot kieu ro rang chu khong phai mang: ban gia trong test va tien trinh that
 * phai tra ve CUNG MOT hop dong, neu khong thi cai test chung minh duoc chi la ban
 * gia cua chinh no.
 *
 * `successful` KHONG dong nghia voi "file dung duoc". ffmpeg tra 0 ma sinh ra file
 * rong hoac khong co luong video la chuyen co that — noi goi phai do lai file, day
 * chi noi ve tien trinh.
 */
final class FfmpegResult
{
    public function __construct(
        public readonly bool $successful,
        public readonly ?int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
    ) {}

    public static function failed(string $stderr, ?int $exitCode = null): self
    {
        return new self(false, $exitCode, '', $stderr);
    }

    /** Doan cuoi cua stderr — cho thong bao loi, ffmpeg noi nguyen nhan o cuoi. */
    public function tail(int $chars = 400): string
    {
        $text = trim($this->stderr);

        return mb_strlen($text) <= $chars ? $text : '…'.mb_substr($text, -$chars);
    }
}
