<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompting\Adapter;

use App\Services\AI\FilmOS\Prompting\IR\StructuredPrompt;

/**
 * Vendor boundary — where organized knowledge becomes vendor language.
 *
 * ALL prompt phrasing lives here: TELEPHOTO → "85mm telephoto compression",
 * FEAR/intense → "terrified", 'weather'=>'cold' → "cold breath vapor",
 * VEHICLE → "no human figures". One renderer per provider; FilmOS never
 * locks into a single vendor's prompt syntax.
 *
 * PER-PRODUCTION: renders a whole StructuredPrompt (all beats) into ONE
 * RenderedPrompt — the benchmark scores one video per scenario, beats woven
 * into a single prompt. (Per-shot rendering + stitching is the separate
 * full-film path, VideoProductionPipeline — a different consumer.)
 *
 * Renderers read ONLY the IR — never View interfaces, never Timeline classes.
 * Anatomy/subject constraints come from typed IR knowledge
 * (SubjectDescriptor::$type carries WorldObjectType) — never regex-guessing
 * strings (the Sprint-3 yacht lesson).
 */
interface PromptRenderer
{
    public function provider(): ProviderId;

    public function render(StructuredPrompt $prompt): RenderedPrompt;
}
