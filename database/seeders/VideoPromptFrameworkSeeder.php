<?php

namespace Database\Seeders;

use App\Models\PromptFramework;
use Illuminate\Database\Seeder;

/**
 * Seeds ONE purpose=video PromptFramework row, reusing the existing
 * phase1_analyze/phase2_diagnose/phase3_generate column shape as the three
 * video-pipeline stages (Fact Extractor / Story Planner / Script Generator).
 *
 * These prompts are written fresh for this purpose -- the existing
 * article-writing prompts (purpose=article) produce a full long-form article
 * (title/content/faq/meta_description), a completely different output shape
 * from what the video pipeline needs (structured facts, then a cliffhanger
 * outline, then per-scene narration+image prompts). Nothing here is copied
 * verbatim from the article frameworks; only the {domain}/{audience}/
 * {tone_notes}/{hook_style} context-injection convention is reused, via
 * CategoryContext::forCategoryVideo(), so the video's voice matches the
 * article's.
 *
 * After running this seeder, wire it to a category via:
 *   CategoryContext::where('category_id', $id)->update(['video_framework_id' => $framework->id]);
 */
class VideoPromptFrameworkSeeder extends Seeder
{
    public function run(): void
    {
        PromptFramework::updateOrCreate(
            ['name' => 'video_pipeline_default'],
            [
                'purpose' => 'video',
                'group_description' => 'Default video-pipeline framework: Fact Extractor (phase1) -> '
                    . 'Story Planner (phase2) -> Script Generator (phase3, one call per part).',
                'system_prompt' => $this->systemPrompt(),
                'phase1_analyze' => $this->factExtractorPrompt(),
                'phase2_diagnose' => $this->storyPlannerPrompt(),
                'phase3_generate' => $this->scriptGeneratorPrompt(),
                'version' => 1,
                'is_active' => true,
            ]
        );
    }

    private function systemPrompt(): string
    {
        return <<<'TXT'
You are a short-form video script writer working from an already-published news/history
article. You write for vertical Shorts/Reels (30-60 seconds per part). Always respond with
ONLY a single valid JSON object -- no markdown fences, no commentary before or after it.
TXT;
    }

    /**
     * Placeholders: {domain} {audience} {terminology} {article_title} {article_content}
     * Output: {"confidence": "high|medium|low", "facts": [...], "entities": {...}}
     */
    private function factExtractorPrompt(): string
    {
        return <<<'TXT'
Domain: {domain}
Audience: {audience}
Domain terminology to recognize: {terminology}

Article title: {article_title}

Article content:
{article_content}

Extract every discrete, independently-checkable fact from this article. For each fact, note
what it would look like visually if illustrated (visual_relevance) and the exact source
sentence/excerpt it came from (source_excerpt) -- this lets a later step ground image prompts
and video narration in real, citable facts instead of generic filler (a thin, fact-free
narration is exactly the pattern platform policies flag as "minimal creative input").

Also rate your own confidence in this extraction: "low" if the article was sparse, vague, or
ambiguous and you had to infer a lot; "medium" if mostly clear with a few gaps; "high" if the
article gave you clean, specific, well-sourced facts to work with.

Respond with ONLY this JSON shape:
{
  "confidence": "high|medium|low",
  "facts": [
    {"id": "f1", "statement": "...", "category": "...", "visual_relevance": "...", "source_excerpt": "..."}
  ],
  "entities": {
    "people": ["..."],
    "places": ["..."],
    "objects": ["..."],
    "time_periods": ["..."]
  }
}
TXT;
    }

    /**
     * Placeholders: {domain} {audience} {tone_notes} {hook_style} {art_style} {hook}
     *   {viral_score} {total_parts} {facts_summary}
     * Output: {"narrative_arc": "...", "mood": "...", "visual_anchor": "...", "parts_outline": [...]}
     */
    private function storyPlannerPrompt(): string
    {
        return <<<'TXT'
Domain: {domain}
Audience: {audience}
Tone for this category: {tone_notes}
This category's hook style: {hook_style}
Required art style for every image in this video: {art_style}

This article already has a proven hook (written and scored by the article pipeline, viral
score {viral_score}/100) -- do NOT invent a new one. Extend and adapt this exact hook into a
{total_parts}-part cliffhanger video series:

  "{hook}"

Facts available to draw on:
{facts_summary}

Plan exactly {total_parts} parts. Every part except the last must end on a genuine, specific
cliffhanger question that makes someone want the next part -- not a generic teaser. The LAST
part (part_number = {total_parts}) must NOT have a cliffhanger_question; instead give it a
short call-to-action (cta) wrapping up the series.

Every part's "beat" must reference something concrete and specific from the facts above, not
a fill-in-the-blank formula where only the topic noun changes -- platforms increasingly detect
and demonetize mass-produced, minimal-substance content, so each part needs real substance.

Also choose one overall "mood" tag for background music selection: one of
epic, calm, mysterious, tense, hopeful.

Write a "visual_anchor": a single fixed, detailed description (appearance, clothing/gear,
colors, distinguishing features) of this video's main subject(s), in the {art_style} style.
Every scene's image prompt across every part of this series will reuse this exact description
so the subject looks like the same person/thing throughout the whole series, not a different
rendering each time. The visual_anchor must describe people/objects GENERICALLY -- physical
build, generic clothing colors/shapes, a generic version of any equipment -- and must NEVER
name or describe any real team logo, league logo, brand logo, trademarked symbol, jersey
number tied to a real team, or any other copyrighted/trademarked visual element. If the article
is about a real, identifiable person, describe them as a generic character inspired by the role
(e.g. "a tall athletic football player in a plain dark jersey, no visible logos or numbers"),
never by name-dropping copyrighted team branding.

Respond with ONLY this JSON shape:
{
  "narrative_arc": "...",
  "mood": "epic|calm|mysterious|tense|hopeful",
  "visual_anchor": "...",
  "parts_outline": [
    {"part_number": 1, "beat": "...", "cliffhanger_question": "...", "is_final_part": false, "cta": null},
    {"part_number": {total_parts}, "beat": "...", "cliffhanger_question": null, "is_final_part": true, "cta": "..."}
  ]
}
TXT;
    }

    /**
     * Placeholders: {domain} {audience} {tone_notes} {art_style} {visual_anchor}
     *   {part_number} {total_parts} {beat} {cliffhanger_or_cta_instruction} {facts_json}
     *   {target_seconds}
     * Output: {"scenes": [...]}
     * Called once per part (Script Generator's "phase3" reused per-job, not per-article).
     */
    private function scriptGeneratorPrompt(): string
    {
        return <<<'TXT'
Domain: {domain}
Audience: {audience}
Tone: {tone_notes}

Required art style for every image_prompt below: {art_style}

This video series' fixed visual_anchor -- every scene's image_prompt MUST describe this same
subject consistently (same appearance, clothing, colors, features as written here), so the
subject doesn't look like a different character from scene to scene or part to part:
  "{visual_anchor}"

You are writing Part {part_number} of {total_parts} for this video series.
This part's planned beat: {beat}
{cliffhanger_or_cta_instruction}

Facts available (cite specific ones -- do not write generic, fact-free narration):
{facts_json}

Target length: approximately {target_seconds} seconds of narration (roughly 2.5 words/second).

Break this part into 3-6 short scenes. For each scene write: the exact narration text to be
read aloud, a visual description of what should be shown, a fully-formed image-generation
prompt derived from that visual description, and which fact id(s) (from the facts above) this
scene's narration draws on (fact_refs) -- every scene should cite at least one fact id where
the narration makes a factual claim.

Every image_prompt must: (1) end with the exact required art style above, (2) describe the
visual_anchor's subject the same way every time -- do not vary their appearance, outfit, or
colors between scenes, (3) NEVER include any real team/league/brand logo, trademarked symbol,
jersey number tied to a real team, watermark, or any other copyrighted/trademarked visual
element, even if the underlying fact mentions a real team or brand name -- describe equipment/
clothing generically instead (e.g. "a plain dark athletic jersey", not a named team's actual
uniform design).

Tag each scene with a "beat" of: hook, reveal, tense, dramatic, transition, or fade -- this
selects which Ken Burns camera-motion style the renderer uses, so vary it scene-to-scene
rather than repeating the same tag.

Respond with ONLY this JSON shape:
{
  "scenes": [
    {
      "scene_id": "s1",
      "beat": "hook|reveal|tense|dramatic|transition|fade",
      "narration": "...",
      "visual_description": "...",
      "image_prompt": "...",
      "fact_refs": ["f1", "f3"]
    }
  ]
}
TXT;
    }
}
