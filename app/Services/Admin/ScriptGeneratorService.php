<?php

namespace App\Services\Admin;

use App\Models\Article;
use App\Models\ArticleFact;
use App\Models\StoryPlan;
use App\Models\VideoJob;
use App\Services\Admin\Concerns\VideoPipelineHelpers;
use Illuminate\Support\Facades\Log;

/**
 * Script Generator: Claude Haiku 4.5, one call per part. Consumes one
 * parts_outline entry at a time (which already says whether it's final and
 * what its CTA is) + the article's facts -- a narrowed, mechanical task
 * (fill in a pre-planned structure, not invent one), which is why Haiku is
 * reliable here even though it wasn't trusted with the planning step.
 *
 * Writes one video_jobs row per part with status=script_ready -- this row
 * IS the job Python's pipeline.py claims and renders.
 */
class ScriptGeneratorService
{
    use VideoPipelineHelpers;

    private const TARGET_SECONDS_PHASE1 = 15;
    private const VISUAL_IMAGE_SCENES   = 10;

    public function __construct(
        private ClaudeWriterService $claude,
        private VideoPromptBuilderService $promptBuilder,
    ) {
    }

    public function run(Article $article, ArticleFact $facts, StoryPlan $plan): void
    {
        if ($plan->content_type === 'visual_image') {
            $this->runVisualImage($article, $facts, $plan);
            return;
        }

        [$context, $framework] = $this->resolveVideoFramework($article->category_id);
        $factsJson = json_encode($facts->facts_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $artStyle = $this->resolveArtStyle($context);

        // Load all existing part numbers in a single query instead of one per part.
        $existingParts = VideoJob::where('story_plan_id', $plan->id)
            ->pluck('part_number')
            ->flip()
            ->all();

        foreach ($plan->parts_outline_json as $partOutline) {
            $partNumber = $partOutline['part_number'];

            if (isset($existingParts[$partNumber])) {
                continue; // idempotent -- never regenerate a part that already has a job
            }

            $isFinal = (bool) ($partOutline['is_final_part'] ?? false);
            // ?? fallback: Claude is instructed to always include both keys (one null),
            // but a missing key here must degrade gracefully, not silently send Claude
            // a prompt with a blank instruction and no error signal.
            $cliffOrCtaInstruction = $isFinal
                ? "This is the FINAL part. End with this call-to-action: " . ($partOutline['cta'] ?? 'Thanks for watching the series!')
                : "End this part on this cliffhanger question (do not answer it): " . ($partOutline['cliffhanger_question'] ?? 'What happens next?');

            $prompt = $this->promptBuilder->inject($framework->phase3_generate, [
                'domain' => $context->domain,
                'audience' => $context->audience,
                'tone_notes' => $context->tone_notes,
                'art_style' => $artStyle,
                'visual_anchor' => $plan->visual_anchor ?: $this->defaultVisualAnchor($artStyle),
                'part_number' => (string) $partNumber,
                'total_parts' => (string) $plan->total_parts,
                'beat' => $partOutline['beat'] ?? '',
                'cliffhanger_or_cta_instruction' => $cliffOrCtaInstruction,
                'facts_json' => $factsJson,
                'target_seconds' => (string) self::TARGET_SECONDS_PHASE1,
            ]);

            $response = $this->claude->generate($prompt, 'haiku', $framework->system_prompt);
            $this->logVideoCost($article->id, 'script_generator', 'haiku', $response);

            $parsed = $this->parseJson($response->text);
            if ($parsed === null || empty($parsed['scenes'])) {
                // Failure mode #5: one bad part must not stop the rest from being scripted.
                // WARNING: if any part is skipped, VideoJobController::status() will never
                // return 'ok' because count(videoJobs) < total_parts. The article will
                // stay 'pending' in the UI until the cron retries and succeeds on this part.
                Log::error('[ScriptGenerator] Failed to generate scenes for part — article will stay pending until retry', [
                    'article_id'  => $article->id,
                    'part_number' => $partNumber,
                    'total_parts' => $plan->total_parts,
                ]);
                continue;
            }

            $this->createVideoJob($plan, $partNumber, $parsed['scenes'], $partOutline['cta'] ?? null);
        }
    }

    /**
     * visual_image pipeline: ask Claude for 10 image-only scenes (no narration).
     * Each scene = scene_id + beat + image_prompt. Python generates images with
     * fal_flux dev, applies Ken Burns, adds music — no TTS, no subtitles.
     */
    private function runVisualImage(Article $article, ArticleFact $facts, StoryPlan $plan): void
    {
        $existing = VideoJob::where('story_plan_id', $plan->id)->where('part_number', 1)->first();
        if ($existing) {
            return;
        }

        [$context, $framework] = $this->resolveVideoFramework($article->category_id);
        $artStyle = $this->resolveArtStyle($context);
        $factsJson = json_encode($facts->facts_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $nScenes  = self::VISUAL_IMAGE_SCENES;
        $nSeconds = self::TARGET_SECONDS_PHASE1;
        $prompt = <<<PROMPT
You are a visual content director creating a stunning {$context->domain} video slideshow.

Visual anchor: {$plan->visual_anchor}
Art style: {$artStyle}
Hook (video title): {$plan->hook}
Facts about this topic:
{$factsJson}

Generate exactly {$nScenes} image scenes for a {$nSeconds}-second slideshow.
Each scene gets {$nSeconds}/{$nScenes} = 1.5 seconds of screen time.

Return ONLY a JSON object (no markdown, no explanation):
{
  "scenes": [
    {
      "scene_id": "s1",
      "beat": "hook",
      "narration": "",
      "visual_description": "...",
      "image_prompt": "detailed, cinematic image generation prompt",
      "fact_refs": []
    }
  ]
}

Beats to use in order: hook, reveal, reveal, build, build, tense, dramatic, dramatic, climax, fade.
Image prompts must be vivid, cinematic, and visually distinct from each other.
No logos, no text overlays, no watermarks. Art style: {$artStyle}.
PROMPT;

        $response = $this->claude->generate($prompt, 'haiku', $framework->system_prompt);
        $this->logVideoCost($article->id, 'script_generator_visual', 'haiku', $response);

        $parsed = $this->parseJson($response->text);
        if ($parsed === null || empty($parsed['scenes'])) {
            Log::error('[ScriptGenerator] visual_image: failed to parse scenes', ['article_id' => $article->id]);
            return;
        }

        $this->createVideoJob($plan, 1, $parsed['scenes'], null);
    }

    private function createVideoJob(StoryPlan $plan, int $partNumber, array $scenes, ?string $cta): void
    {
        VideoJob::create([
            'story_plan_id' => $plan->id,
            'part_number'   => $partNumber,
            'status'        => 'script_ready',
            'script_json'   => [
                'hook'           => $plan->hook,
                'cta'            => $cta,
                'target_seconds' => self::TARGET_SECONDS_PHASE1,
                'scenes'         => $scenes,
            ],
        ]);
    }
}
