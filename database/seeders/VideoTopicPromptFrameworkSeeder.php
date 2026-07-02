<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\CategoryContext;
use App\Models\PromptFramework;
use Illuminate\Database\Seeder;

/**
 * Topic-specific video frameworks -- the generic VideoPromptFrameworkSeeder
 * (video_pipeline_default) uses one shared prompt structure for every
 * category, only varying domain/audience/tone_notes/art_style. This seeder
 * instead writes distinct phase2/phase3 narrative guidance PER topic
 * (narrative angle + topic-appropriate safety/copyright caution differs by
 * subject matter -- archaeology's caution is "don't imply artifact
 * ownership," health's is "don't read as medical advice," superyacht's is
 * "don't name a real vessel/builder," weird news' is "stay fact-grounded
 * despite the entertainment angle"), while keeping the exact same JSON
 * output contract as video_pipeline_default so FactExtractorService/
 * StoryPlannerService/ScriptGeneratorService need zero code changes.
 *
 * After running, wire each category to its framework + art_style via:
 *   CategoryContext::where('category_id', $id)->update([
 *       'video_framework_id' => $frameworkId, 'art_style' => $style,
 *   ]);
 * (this seeder does that wiring itself for the 4 topics below.)
 */
class VideoTopicPromptFrameworkSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedTopic(
            categoryName: 'Archaeology',
            domain: 'Archaeology & Ancient History',
            audience: 'History enthusiasts, museum-goers, curious general audience fascinated by ancient civilizations',
            toneNotes: 'Awe-driven but credible -- wonder at discovery, never tabloid-sensational about "ancient mysteries"',
            hookStyle: 'Discovery reveal: what was found, why it stuns experts, what it changes about what we thought we knew',
            artStyle: 'realistic historical-documentary illustration, sepia and amber cinematic lighting, richly textured ancient materials (stone, bronze, pottery, weathered parchment), museum-exhibit atmosphere',
            narrativeEmphasis: <<<'TXT'
Center every part on: what physical evidence was found, what method revealed it (excavation,
scan, translation...), what it proves or strongly suggests about the people/event/period, and
what remains genuinely debated or unknown among experts. Avoid vague "ancient mystery" filler --
ground every claim in the specific facts provided.
TXT,
            safetyNote: <<<'TXT'
Do not depict or imply ownership/possession of any specific real museum's actual artifact,
exhibit signage, or any real institution's branding/logo. Describe artifacts, ruins, and
excavation sites generically based on the facts (material, shape, condition, setting) -- never
as a specific real museum's labeled display.
TXT,
        );

        $this->seedTopic(
            categoryName: 'Health',
            domain: 'Health & Medicine',
            audience: 'Health-conscious adults, patients, caregivers, wellness-minded millennials',
            toneNotes: 'Credible and reassuring, never alarmist -- frame as "what research/experts found," not medical advice',
            hookStyle: 'Finding reveal: what the research/study/expert found, why it matters to the viewer, what to watch for',
            artStyle: 'clean modern medical-documentary style, soft realistic lighting, infographic-friendly clarity, reassuring tone, no graphic or gory medical imagery',
            narrativeEmphasis: <<<'TXT'
Center every part on a specific finding/fact (a study result, an expert statement, a documented
case) -- never generic "health tips" filler. Frame information as reporting on research/experts,
not as direct medical advice or a diagnosis. If a part discusses symptoms or treatment, note it
factually (what was found/recommended by the source), without telling the viewer what they
personally should do.
TXT,
            safetyNote: <<<'TXT'
Never depict a real, identifiable patient or doctor from the source article. Never show or name
a real pharmaceutical brand, drug packaging, or medical device logo -- describe medications/
devices generically (e.g. "a small white pill," "a wearable health monitor"). Do not visually
exaggerate symptoms into graphic/gory imagery.
TXT,
        );

        $this->seedTopic(
            categoryName: 'Superyacht',
            domain: 'Superyacht',
            audience: 'HNWI, superyacht owners, charter market, nautical lifestyle enthusiasts',
            toneNotes: 'Aspirational and opulent -- awe at scale/engineering/luxury, never envious or mocking',
            hookStyle: 'Scale/exclusivity reveal: the number, the feature, or the price that makes this vessel extraordinary',
            artStyle: 'ultra-realistic luxury cinematic photography style, golden-hour ocean lighting, glossy reflective surfaces, opulent fine detail, wide cinematic composition',
            narrativeEmphasis: <<<'TXT'
Center every part on a concrete, specific detail from the facts -- a dimension, a feature, a
price, an engineering detail, an owner/builder detail -- not generic "luxury lifestyle" filler.
The awe should come from real specifics, not adjectives alone.
TXT,
            safetyNote: <<<'TXT'
Never name or visually reproduce a real shipyard/builder's brand, logo, hull registration
number, or a specific real vessel's actual name/livery -- describe the yacht generically by its
features (length, deck layout, color, style) even when the source facts name a real vessel.
TXT,
        );

        $this->seedTopic(
            categoryName: 'Weird News',
            domain: 'Weird & Viral News',
            audience: 'General online readers seeking entertainment, water-cooler conversation fodder',
            toneNotes: 'Playful and surprised, but never mocking real people -- the story is the surprise, not ridicule',
            hookStyle: 'Disbelief reveal: the detail that makes someone say "wait, what?" -- specific, not generically "crazy"',
            artStyle: 'quirky illustrated-documentary style, slightly heightened color and energy, eye-catching but grounded in the real scene described, not exaggerated cartoon caricature',
            narrativeEmphasis: <<<'TXT'
Center every part on the specific, verifiable detail that makes this story strange -- a number,
an object, a sequence of events -- not a generic "you won't believe this" framing with no
substance. Platforms aggressively demonetize fact-free "weird news" filler, so every scene must
trace back to a real fact above.
TXT,
            safetyNote: <<<'TXT'
If real, identifiable people are part of the story, depict them respectfully and generically
(do not caricature or mock their appearance) -- the humor/surprise should come from the
situation/event, never from ridiculing a real person's looks or identity.
TXT,
        );
    }

    private function seedTopic(
        string $categoryName,
        string $domain,
        string $audience,
        string $toneNotes,
        string $hookStyle,
        string $artStyle,
        string $narrativeEmphasis,
        string $safetyNote,
    ): void {
        $category = Category::firstOrCreate(
            ['name' => $categoryName],
            ['slug' => \Illuminate\Support\Str::slug($categoryName)]
        );

        $framework = PromptFramework::updateOrCreate(
            ['name' => 'video_' . \Illuminate\Support\Str::slug($categoryName, '_')],
            [
                'purpose' => 'video',
                'group_description' => "Topic-specific video framework: {$categoryName} (Fact Extractor -> Story Planner -> Script Generator).",
                'system_prompt' => $this->systemPrompt(),
                'phase1_analyze' => $this->factExtractorPrompt(),
                'phase2_diagnose' => $this->storyPlannerPrompt($narrativeEmphasis, $safetyNote),
                'phase3_generate' => $this->scriptGeneratorPrompt($safetyNote),
                'version' => 1,
                'is_active' => true,
            ]
        );

        $existingContext = CategoryContext::where('category_id', $category->id)->first();

        if ($existingContext) {
            // This category already has domain/audience/tone_notes/hook_style tuned
            // for the article pipeline -- CategoryContext fields are deliberately
            // shared across purposes (see VideoPromptBuilderService docblock: "so the
            // video's voice matches the article's"), so only add what's new here.
            $existingContext->update([
                'video_framework_id' => $framework->id,
                'art_style' => $artStyle,
            ]);
        } else {
            CategoryContext::create([
                'category_id' => $category->id,
                'domain' => $domain,
                'audience' => $audience,
                'terminology' => [],
                'tone_notes' => $toneNotes,
                'hook_style' => $hookStyle,
                'art_style' => $artStyle,
                'video_framework_id' => $framework->id,
                'is_active' => true,
            ]);
        }

        $this->command?->info("Video framework wired: {$categoryName} -> {$framework->name}");
    }

    private function systemPrompt(): string
    {
        return <<<'TXT'
You are a short-form video script writer working from an already-published news/history
article. You write for vertical Shorts/Reels (30-60 seconds per part). Always respond with
ONLY a single valid JSON object -- no markdown fences, no commentary before or after it.
TXT;
    }

    /** Identical to VideoPromptFrameworkSeeder's -- the fact-extraction task itself doesn't vary by topic. */
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

    private function storyPlannerPrompt(string $narrativeEmphasis, string $safetyNote): string
    {
        return <<<TXT
Domain: {domain}
Audience: {audience}
Tone for this category: {tone_notes}
This category's hook style: {hook_style}
Required art style for every image in this video: {art_style}

L12 Analytics Feedback — what has worked best for this category recently:
{analytics_hint}

This article already has a proven hook (written and scored by the article pipeline, viral
score {viral_score}/100) -- do NOT invent a new one. Extend and adapt this exact hook into a
{total_parts}-part cliffhanger video series:

  "{hook}"

Facts available to draw on:
{facts_summary}

{$narrativeEmphasis}

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
build, generic clothing colors/shapes, a generic version of any equipment.

{$safetyNote}

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

    private function scriptGeneratorPrompt(string $safetyNote): string
    {
        return <<<TXT
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
colors between scenes.

{$safetyNote}

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
