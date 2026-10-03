<?php

namespace App\Enums;

use App\Traits\EnumTrait;

enum PlanningStageName: string
{
    use EnumTrait;

    case INSPIRATION = 'inspiration';
    case CONCEPT = 'concept';
    case ANCHOR_PROMPT = 'anchor_prompt';
    case SCREENPLAY = 'screenplay';
    case SCREENPLAY_FOUNDATION = 'screenplay_foundation';
    case SCREENPLAY_CHARACTERS = 'screenplay_characters';
    case SCREENPLAY_LOCATIONS = 'screenplay_locations';
    case SCENE_PLAN = 'scene_plan';
    case SCENE_PLAN_TRIAL = 'scene_plan_trial';
    case FINALIZE = 'finalize';
    case REFERENCE_PROMPT = 'reference_prompt';
    case VESSEL_DESIGN = 'vessel_design';

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
            self::ANCHOR_PROMPT => self::SCREENPLAY_FOUNDATION,
            self::SCREENPLAY_FOUNDATION => self::SCREENPLAY,
            self::SCREENPLAY_CHARACTERS => self::SCREENPLAY,
            self::SCREENPLAY_LOCATIONS => self::SCREENPLAY,
            self::SCREENPLAY => self::SCENE_PLAN,
            self::SCENE_PLAN => self::FINALIZE,
            self::SCENE_PLAN_TRIAL => null,
            self::FINALIZE => null,
            self::REFERENCE_PROMPT => null,
            self::VESSEL_DESIGN => self::ANCHOR_PROMPT,
        };
    }
}
