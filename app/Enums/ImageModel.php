<?php

namespace App\Enums;

use App\Traits\EnumTrait;

enum ImageModel: string
{
    use EnumTrait;

    case GPT_IMAGE_2 = 'gpt-image-2';
    case GPT_IMAGE_2_5_FLARE = 'gpt-image-2.5-flare';
    case GPT_IMAGE_2_5_SUNBURST = 'gpt-image-2.5-sunburst';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function provider(): string
    {
        return match ($this) {
            self::GPT_IMAGE_2, self::GPT_IMAGE_2_5_FLARE, self::GPT_IMAGE_2_5_SUNBURST => 'openai',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::GPT_IMAGE_2 => 'GPT Image 2',
            self::GPT_IMAGE_2_5_FLARE => 'GPT Image 2.5 Flare — tạo ảnh nhanh',
            self::GPT_IMAGE_2_5_SUNBURST => 'GPT Image 2.5 Sunburst — sửa ảnh chính xác',
        };
    }
}
