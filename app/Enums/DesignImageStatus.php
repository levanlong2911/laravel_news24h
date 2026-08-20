<?php

namespace App\Enums;

use App\Traits\EnumTrait;

/**
 * Vong doi cua mot o thiet ke anh:
 *
 *   CANDIDATE -> QUEUED -> CLAIMED -> RENDERING -> RENDERED -> APPROVED -> SUPERSEDED
 *                  ^                                 |
 *                  +------------ FAILED -------------+   (het lease -> ve QUEUED)
 *
 * APPROVED va SUPERSEDED da co san trong du lieu (6 dong `approved` tren may
 * nay, sinh tu duong session cu) — bo hai gia tri do khoi enum se lam moi cho
 * doc `status` nem ValueError tren chinh du lieu that.
 *
 * CANDIDATE la trang thai MIEN PHI: o da co prompt + thiet lap nhung chua ai
 * tra tien. Chi tu QUEUED tro di moi co the ton tien.
 *
 * CLAIMED khac RENDERING: claimed la da nhan viec, rendering la dang goi
 * provider. Buoc chuyen do KHONG co endpoint rieng — heartbeat dau tien nang
 * trang thai, giong het duong shot (VideoShotRepository::heartbeat()).
 */
enum DesignImageStatus: string
{
    use EnumTrait;

    case CANDIDATE = 'candidate';
    case QUEUED = 'queued';
    case CLAIMED = 'claimed';
    case RENDERING = 'rendering';
    case RENDERED = 'rendered';
    case FAILED = 'failed';
    case APPROVED = 'approved';
    case SUPERSEDED = 'superseded';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::CANDIDATE => 'Candidate',
            self::QUEUED => 'Queued',
            self::CLAIMED => 'Claimed',
            self::RENDERING => 'Rendering',
            self::RENDERED => 'Rendered',
            self::FAILED => 'Failed',
            self::APPROVED => 'Approved',
            self::SUPERSEDED => 'Superseded',
        };
    }

    /**
     * Duoc phep day vao hang doi. RENDERED va APPROVED khong nam trong day: mot
     * o da co anh ma vao lai hang doi la tra tien lan hai cho cung mot thu.
     * FAILED thi co — hong do mang/provider la chuyen tam thoi, chan han se bien
     * mot loi tam thanh ngo cut.
     *
     * @return list<string>
     */
    public static function enqueueableValues(): array
    {
        return [self::CANDIDATE->value, self::FAILED->value];
    }

    /**
     * Dang giu lease cua mot worker — thu hoi duoc khi lease het han.
     *
     * @return list<string>
     */
    public static function leasedValues(): array
    {
        return [self::CLAIMED->value, self::RENDERING->value];
    }
}
