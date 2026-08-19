<?php

namespace App\Enums;

use App\Traits\EnumTrait;

enum SceneStep: string
{
    use EnumTrait;

    case PLANNING = 'planning';
    case PROMPT = 'prompt';
    case REFERENCES = 'references';
    case IMAGE = 'image';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::PLANNING => 'Planning',
            self::PROMPT => 'Prompt',
            self::REFERENCES => 'References',
            self::IMAGE => 'Image',
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::PLANNING => 'Thiết kế kế hoạch',
            self::PROMPT => 'Tạo prompt chi tiết',
            self::REFERENCES => 'Chọn ảnh tham chiếu / neo',
            self::IMAGE => 'Tạo & duyệt hình ảnh keyframe',
        };
    }

    public function ordinal(): int
    {
        return match ($this) {
            self::PLANNING => 1,
            self::PROMPT => 2,
            self::REFERENCES => 3,
            self::IMAGE => 4,
        };
    }

    public function next(): ?self
    {
        return match ($this) {
            self::PLANNING => self::PROMPT,
            self::PROMPT => self::REFERENCES,
            self::REFERENCES => self::IMAGE,
            self::IMAGE => null,
        };
    }
}
