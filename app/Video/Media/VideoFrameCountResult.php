<?php

namespace App\Video\Media;

/**
 * Ket qua dem khung hinh.
 *
 * KHONG dung `?int`: `null` khong phan biet duoc "ffprobe loi", "JSON hong",
 * "khong co luong video" voi "dem duoc dung 0 khung". Bon truong hop do doi hoi bon
 * cach xu ly khac nhau, va gop chung lai thanh mot `null` la bat noi goi phai doan.
 */
final class VideoFrameCountResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?int $frames,
        public readonly ?string $error,
        /**
         * stderr cua ffprobe, KE CA khi no thoat 0.
         *
         * Tach khoi `error` chu khong gop: `error` tra loi "vi sao khong dem duoc",
         * con day tra loi "trong luc dem co gi bat thuong khong". Do that tren
         * `fc_truncated.mp4`: thoat 0, tra ve 53 khung, stderr day `Invalid NAL unit
         * size` — dem du khung KHONG chung minh doc sach.
         */
        public readonly string $diagnostics = '',
    ) {}

    public static function counted(int $frames, string $diagnostics = ''): self
    {
        return new self(true, $frames, null, $diagnostics);
    }

    public static function failed(string $error, string $diagnostics = ''): self
    {
        return new self(false, null, $error, $diagnostics);
    }
}
