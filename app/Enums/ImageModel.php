<?php

namespace App\Enums;

use App\Traits\EnumTrait;

enum ImageModel: string
{
    use EnumTrait;

    case GPT_IMAGE_2 = 'gpt-image-2';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::GPT_IMAGE_2 => 'GPT Image 2',
        };
    }
}
