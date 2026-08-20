<?php

namespace App\Enums;

use App\Traits\EnumTrait;

enum ImageResolution: string
{
    use EnumTrait;

    case SQUARE = '1024x1024';
    case LANDSCAPE = '1536x1024';
    case PORTRAIT = '1024x1536';
    case SQUARE_2K = '2048x2048';
    case LANDSCAPE_2K = '2048x1152';
    case LANDSCAPE_4K = '3840x2160';
    case PORTRAIT_4K = '2160x3840';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function width(): int
    {
        return (int) explode('x', $this->value)[0];
    }

    public function height(): int
    {
        return (int) explode('x', $this->value)[1];
    }

    public function label(): string
    {
        $w = $this->width();
        $h = $this->height();
        $divisor = self::greatestCommonDivisor($w, $h);

        return sprintf('%d × %d (%d:%d)', $w, $h, $w / $divisor, $h / $divisor);
    }

    private static function greatestCommonDivisor(int $a, int $b): int
    {
        return $b === 0 ? $a : self::greatestCommonDivisor($b, $a % $b);
    }
}
