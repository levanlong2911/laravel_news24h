<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompting\Adapter;

use App\Services\AI\FilmOS\Prompting\Plan\RenderPlan;

/**
 * Vendor boundary — where organized knowledge becomes vendor language.
 *
 * ALL prompt phrasing lives here: TELEPHOTO → "85mm telephoto compression",
 * FEAR/intense → "terrified", 'weather'=>'cold' → "cold breath vapor",
 * VEHICLE → "no human figures". One renderer per provider; FilmOS never
 * locks into a single vendor's prompt syntax.
 *
 * PER-PRODUCTION: renders a whole RenderPlan (all beats) into ONE
 * RenderedPrompt — the benchmark scores one video per scenario, beats woven
 * into a single prompt. (Per-shot rendering + stitching is the separate
 * full-film path, VideoProductionPipeline — a different consumer.)
 *
 * A renderer decides HOW to say things, never WHAT to say: ownership, staging,
 * ordering and importance are already settled in the RenderPlan by the
 * RenderPlanner, which knows no vendor. The one decision left that is genuinely
 * vendor-owned is how much fits — only this class knows what its own wording
 * costs in words, and a word budget is a fact about a provider, not a story.
 *
 * Renderers read ONLY the plan — never View interfaces, never Timeline classes.
 * Anatomy/subject constraints come from typed knowledge
 * (SubjectDescriptor::$type carries WorldObjectType) — never regex-guessing
 * strings (the Sprint-3 yacht lesson).
 */
interface PromptRenderer
{
    public function provider(): ProviderId;

    public function render(RenderPlan $plan): RenderedPrompt;
}
