<?php

namespace App\Enums;

use App\Traits\EnumTrait;

enum ImageVariations: int
{
    use EnumTrait;

    case ONE = 1;
    case TWO = 2;

    /** @return list<int> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::ONE => '1 ảnh',
            self::TWO => '2 ảnh (so sánh)',
        };
    }
}
