<?php

namespace App\Enums;

use App\Traits\EnumTrait;

enum ImageQuality: string
{
    use EnumTrait;

    case LOW = 'low';
    case MEDIUM = 'medium';
    case HIGH = 'high';
    case XHIGH = 'xhigh';
    case MAX = 'max';
    case AUTO = 'auto';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::LOW => 'Low',
            self::MEDIUM => 'Medium',
            self::HIGH => 'High',
            self::XHIGH => 'XHigh',
            self::MAX => 'Max',
            self::AUTO => 'Auto',
        };
    }

    /**
     * Doc quality tu mot spec da luu. Gia tri la hoac vang thi lay HIGH: uoc cao
     * con sua duoc, uoc thap thi so cai noi doi ve so tien da tieu.
     */
    public static function fromSpecOrHigh(mixed $value): self
    {
        return self::tryFrom((string) $value) ?? self::HIGH;
    }

    public function estimatedCostUsd(): ?float
    {
        return match ($this) {
            self::LOW => 0.015,
            self::MEDIUM => 0.041,
            self::HIGH => 0.11,
            self::AUTO => 0.11,
            self::XHIGH, self::MAX => null,
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::LOW => 'Nháp nhanh, ảnh xem thử',
            self::MEDIUM => 'Ảnh neo — dùng lại cho mọi cảnh sau',
            self::HIGH => 'Chữ dày, sơ đồ, sửa ảnh nhạy danh tính',
            self::XHIGH => 'Chi tiết hơn High, chậm hơn — chỉ GPT Image 2.5, chưa có ước tính giá',
            self::MAX => 'Mức cao nhất, chậm nhất — chỉ GPT Image 2.5, chưa có ước tính giá',
            self::AUTO => 'Để nhà cung cấp chọn',
        };
    }
}
