<?php

namespace App\Video\FinalComposition;

/**
 * Hinh dang cua file dau ra.
 *
 * Khong co mac dinh. Chon khung hinh la quyet dinh ve bo cuc va chat luong — mot
 * du an co the lan 720x1280 voi 1080x1920, va "co nguon" khi do khong ton tai.
 */
final class CompositionProfile
{
    public function __construct(
        public readonly int $width,
        public readonly int $height,
        public readonly int $fps,
        public readonly int $crf,
        public readonly int $sampleRate = 48000,
        public readonly int $channels = 2,
    ) {}

    /**
     * Doi so khung sang giay, dinh dang CO DINH.
     *
     * Graph la mot chuoi phai tai lap duoc: `0.5` va `0.500000` la hai graph khac
     * nhau voi mot phep so chuoi, du chung dieu khien ffmpeg y het nhau.
     */
    public function seconds(int $frames): string
    {
        return number_format($frames / $this->fps, 6, '.', '');
    }

    public function milliseconds(int $ms): string
    {
        return number_format($ms / 1000, 6, '.', '');
    }
}
