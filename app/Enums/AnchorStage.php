<?php

namespace App\Enums;

use App\Traits\EnumTrait;

enum AnchorStage: string
{
    use EnumTrait;

    case FABRICATION_GEOMETRY_ANCHOR = 'fabrication_geometry_anchor';
    case FINISHED_IDENTITY_ANCHOR = 'finished_identity_anchor';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::FABRICATION_GEOMETRY_ANCHOR => 'Hình khối — thử nghiệm, để kiểm khối lượng thân',
            self::FINISHED_IDENTITY_ANCHOR => 'Hoàn thiện — ảnh neo nhận dạng',
        };
    }
}
