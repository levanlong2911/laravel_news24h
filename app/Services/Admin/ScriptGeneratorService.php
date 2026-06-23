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

    private const TARGET_SECONDS_PHASE1 = 45;

    public function __construct(
        private ClaudeWriterService $claude,
        private VideoPromptBuilderService $promptBuilder,
    ) {
    }

    public function run(Article $article, ArticleFact $facts, StoryPlan $plan): void
    {
        [$context, $framework] = $this->resolveVideoFramework($article->category_id);
        $factsJson = json_encode($facts->facts_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $artStyle = $context->art_style ?: config('video.default_art_style');

        foreach ($plan->parts_outline_json as $partOutline) {
            $partNumber = $partOutline['part_number'];

            $existing = VideoJob::where('story_plan_id', $plan->id)
                ->where('part_number', $partNumber)
                ->first();
            if ($existing) {
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
                'visual_anchor' => $plan->visual_anchor ?: "Generic subject matching this topic, {$artStyle}, no logos or trademarked symbols.",
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
                // Failure mode #5's spirit applied at part granularity: one bad part
                // must not stop the rest of this article's parts from being scripted.
                Log::error('[ScriptGenerator] Failed to generate scenes for part', [
                    'article_id' => $article->id,
                    'part_number' => $partNumber,
                ]);
                continue;
            }

            VideoJob::create([
                'story_plan_id' => $plan->id,
                'part_number' => $partNumber,
                'status' => 'script_ready',
                'script_json' => [
                    'hook' => $plan->hook,
                    'cta' => $partOutline['cta'] ?? null,
                    'target_seconds' => self::TARGET_SECONDS_PHASE1,
                    'scenes' => $parsed['scenes'],
                ],
            ]);
        }
    }
}
