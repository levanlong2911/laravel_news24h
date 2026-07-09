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
article. You write for vertical Shorts/Reels of approximately 15 seconds. Always respond with
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
     *   {viral_score} {facts_summary} {analytics_hint}
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

L12 Analytics Feedback — what has worked best for this category recently:
{analytics_hint}

This article already has a proven hook (written and scored by the article pipeline, viral
score {viral_score}/100) -- do NOT invent a new one. Build a single, self-contained 15-second
short-form video around this exact hook:

  "{hook}"

Facts available to draw on:
{facts_summary}

Plan ONE part only (part_number=1, is_final_part=true). This is a complete standalone video
— no cliffhanger, no "continued in part 2". It must open with the hook, build tension or
reveal through the middle, and end with a strong call-to-action (cta) that encourages
likes/comments/follows.

The beat must reference something concrete and specific from the facts above — platforms
detect and demonetize generic, fact-free content, so the video needs real substance.

Also choose one overall "mood" tag for background music selection: one of
epic, calm, mysterious, tense, hopeful.

Write a "visual_anchor": a single fixed, detailed description (appearance, clothing/gear,
colors, distinguishing features) of this video's main subject(s), in the {art_style} style.
Every scene's image prompt will reuse this exact description so the subject looks consistent
throughout. The visual_anchor must describe people/objects GENERICALLY — physical build,
generic clothing colors/shapes, a generic version of any equipment — and must NEVER name
or describe any real team logo, brand logo, trademarked symbol, or copyrighted visual element.
If the article is about a real person, describe them as a generic character inspired by the
role (e.g. "a tall athletic football player in a plain dark jersey, no visible logos"),
never by name-dropping copyrighted branding.

Classify the content_type based on the story:
  "visual_image" — the story is primarily about scenes, locations, objects, or visual spectacle (travel, architecture, luxury, nature, animals in their environment).
  "informational" — the story centres on news events, facts, people, or data.

Respond with ONLY this JSON shape:
{
  "narrative_arc": "...",
  "mood": "epic|calm|mysterious|tense|hopeful",
  "content_type": "informational|visual_image",
  "visual_anchor": "...",
  "parts_outline": [
    {"part_number": 1, "beat": "...", "cliffhanger_question": null, "is_final_part": true, "cta": "..."}
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

This video's fixed visual_anchor -- every scene's image_prompt MUST describe this same
subject consistently (same appearance, clothing, colors, features as written here), so the
subject looks the same in both scenes:
  "{visual_anchor}"

This video's planned beat: {beat}
{cliffhanger_or_cta_instruction}

Facts available (cite specific ones -- do not write generic, fact-free narration):
{facts_json}

Target length: {target_seconds} seconds total. Write EXACTLY 2 scenes:
  - Scene s1 (beat = hook/reveal/dramatic): the main story — ~10 seconds, ~25 words of narration.
    This is the longer clip that hooks the viewer and delivers the key facts.
  - Scene s2 (beat = end): the closing CTA — ~5 seconds, ~12 words of narration.
    This wraps up with a call-to-action (like/follow/comment).

Do NOT write more or fewer than 2 scenes. Exceeding 2 scenes breaks the video layout.

For each scene write: the narration text (s1 ≤ 27 words, s2 ≤ 14 words), a visual description
of what should be shown, a fully-formed image-generation prompt, and fact_refs citing at least
one fact id where the narration makes a factual claim.

Every image_prompt must: (1) end with the exact required art style above, (2) describe the
visual_anchor's subject the same way every time -- do not vary their appearance, outfit, or
colors between scenes, (3) NEVER include any real team/league/brand logo, trademarked symbol,
jersey number tied to a real team, watermark, or any other copyrighted/trademarked visual
element, even if the underlying fact mentions a real team or brand name -- describe equipment/
clothing generically instead (e.g. "a plain dark athletic jersey", not a named team's actual
uniform design).

Tag each scene with a "beat" — s1 must be one of: hook, reveal, tense, dramatic, climax,
transition. s2 must always be: end. These drive the camera-motion and lighting style.

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
