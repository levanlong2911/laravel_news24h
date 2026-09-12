<?php

namespace App\Enums;

use App\Traits\EnumTrait;

enum PlanningStageName: string
{
    use EnumTrait;

    case INSPIRATION = 'inspiration';
    case CONCEPT = 'concept';
    case ANCHOR_PROMPT = 'anchor_prompt';
    case SCENE_PLAN = 'scene_plan';
    case FINALIZE = 'finalize';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function next(): ?self
    {
        return match ($this) {
            self::INSPIRATION => self::CONCEPT,
            self::CONCEPT => self::ANCHOR_PROMPT,
            self::ANCHOR_PROMPT => self::SCENE_PLAN,
            self::SCENE_PLAN => self::FINALIZE,
            self::FINALIZE => null,
        };
    }
}
