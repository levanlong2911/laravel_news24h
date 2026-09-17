<?php

namespace App\Video\FinalComposition;

/**
 * Ngan sach thoi gian cho CA LUOT, khong phai cho tung tien trinh.
 *
 * Dat ra truoc khi cham vao file dau tien, roi moi lan goi ffmpeg/ffprobe deu phai
 * xin phan con lai. Dat dong ho o giua luot roi kiem o cuoi thi khong dung duoc gi:
 * luc do da tieu het thoi gian roi.
 *
 * Dung `hrtime()` chu khong `microtime()`: dong ho he thong co the bi chinh giua
 * chung, va mot ngan sach dua tren no se nhay theo.
 */
final class Deadline
{
    private function __construct(private readonly float $expiresAtNs) {}

    public static function in(int $seconds): self
    {
        return new self((float) hrtime(true) + $seconds * 1_000_000_000);
    }

    public function expired(): bool
    {
        return (float) hrtime(true) >= $this->expiresAtNs;
    }

    /**
     * Giay con lai, toi thieu 1.
     *
     * @throws CompositionRefused khi da het han — het gio thi KHONG duoc khoi dong
     *                            them mot tien trinh nao nua
     */
    public function remaining(): int
    {
        $left = ($this->expiresAtNs - (float) hrtime(true)) / 1_000_000_000;

        if ($left <= 0) {
            throw new CompositionRefused('het ngan sach thoi gian cua luot chay');
        }

        return max(1, (int) ceil($left));
    }
}
