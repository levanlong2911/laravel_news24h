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
    case FINALIZE = 'finalize';
    case REFERENCE_PROMPT = 'reference_prompt';
    case VESSEL_DESIGN = 'vessel_design';
    case DESIGN_DNA = 'design_dna';
    case DNA_NOVELTY = 'dna_novelty';
    case DNA_PLAUSIBILITY = 'dna_plausibility';
    case YACHT_DESIGN = 'yacht_design';
    case MODEL_SHEET = 'model_sheet';
    case MODEL_SHEET_REVIEW = 'model_sheet_review';
    case MODEL_SHEET_PROMPT = 'model_sheet_prompt';
    case MODEL_SHEET_PROMPT_APPROVAL = 'model_sheet_prompt_ok';
    // Phase 3R: ke hoach + snapshot nguon, manifest ung vien, duyet pack; khoa thiet ke.
    case REFERENCE_PACK_PLAN = 'reference_pack_plan';
    case REFERENCE_PACK = 'reference_pack';
    case REFERENCE_PACK_REVIEW = 'reference_pack_review';
    case DESIGN_LOCK = 'design_lock';

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
            self::FINALIZE => null,
            self::REFERENCE_PROMPT => null,
            self::VESSEL_DESIGN => self::ANCHOR_PROMPT,
            self::DESIGN_DNA => self::YACHT_DESIGN,
            self::DNA_NOVELTY => self::YACHT_DESIGN,
            self::DNA_PLAUSIBILITY => self::YACHT_DESIGN,
            self::YACHT_DESIGN => null,
            self::MODEL_SHEET => self::MODEL_SHEET_REVIEW,
            self::MODEL_SHEET_REVIEW => null,
            self::MODEL_SHEET_PROMPT => self::MODEL_SHEET_PROMPT_APPROVAL,
            self::MODEL_SHEET_PROMPT_APPROVAL => null,
            self::REFERENCE_PACK_PLAN => self::REFERENCE_PACK,
            self::REFERENCE_PACK => self::REFERENCE_PACK_REVIEW,
            self::REFERENCE_PACK_REVIEW => null,
            self::DESIGN_LOCK => null,
        };
    }
}
