<?php

namespace App\Enums;

use App\Traits\EnumTrait;

enum ImageModel: string
{
    use EnumTrait;

    case GPT_IMAGE_2 = 'gpt-image-2';
    case GPT_IMAGE_2_6_FLARE = 'gpt-image-2.6-flare';
    case GPT_IMAGE_2_6_SUNBURST = 'gpt-image-2.6-sunburst';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function provider(): string
    {
        return match ($this) {
            self::GPT_IMAGE_2, self::GPT_IMAGE_2_6_FLARE, self::GPT_IMAGE_2_6_SUNBURST => 'openai',
        };
    }

    /** @return list<ImageQuality> */
    public function qualities(): array
    {
        return match ($this) {
            self::GPT_IMAGE_2 => [ImageQuality::LOW, ImageQuality::MEDIUM, ImageQuality::HIGH, ImageQuality::AUTO],
            self::GPT_IMAGE_2_6_FLARE, self::GPT_IMAGE_2_6_SUNBURST => [
                ImageQuality::LOW, ImageQuality::MEDIUM, ImageQuality::HIGH,
                ImageQuality::XHIGH, ImageQuality::MAX, ImageQuality::AUTO,
            ],
        };
    }

    public function supports(ImageQuality $quality): bool
    {
        return in_array($quality, $this->qualities(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::GPT_IMAGE_2 => 'GPT Image 2',
            self::GPT_IMAGE_2_6_FLARE => 'GPT Image 2.6 Flare — tạo và sửa ảnh nhanh',
            self::GPT_IMAGE_2_6_SUNBURST => 'GPT Image 2.6 Sunburst — ảnh chi tiết, sửa chính xác',
        };
    }
}
