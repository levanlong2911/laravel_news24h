<?php

namespace App\Services\Admin;

use App\Models\Article;
use App\Models\ArticleFact;
use App\Models\StoryPlan;
use App\Services\Admin\Concerns\VideoPipelineHelpers;

/**
 * Story Planner: Claude Sonnet 4.6. Does NOT invent a hook from scratch --
 * the article already has a proven, scored hook line (Sonnet-written,
 * specifically engineered as a "Hook + Tension" attention-grabber --
 * article.fb_image_text; falls back to article.title if that's empty).
 * The raw HookEngine candidate strings themselves are never persisted past
 * the original WriteArticleJob run, so fb_image_text is the closest
 * available persisted proxy for "the hook that was actually used."
 *
 * Story Planner extends/adapts that hook into a multi-part cliffhanger
 * structure rather than generating an independent, possibly inconsistent
 * hook for the video series.
 */
class StoryPlannerService
{
    use VideoPipelineHelpers;

    public function __construct(
        private ClaudeWriterService $claude,
        private VideoPromptBuilderService $promptBuilder,
    ) {
    }

    public function run(Article $article, ArticleFact $facts, int $totalParts = 3): StoryPlan
    {
        $existing = StoryPlan::where('article_id', $article->id)->first();
        if ($existing) {
            return $existing;
        }

        [$context, $framework] = $this->resolveVideoFramework($article->category_id);

        $hook = $article->fb_image_text ?: $article->title;

        $factsSummary = collect($facts->facts_json)
            ->map(fn (array $f) => "- [{$f['id']}] {$f['statement']}")
            ->implode("\n");

        $artStyle = $context->art_style ?: config('video.default_art_style');

        $prompt = $this->promptBuilder->inject($framework->phase2_diagnose, [
            'domain' => $context->domain,
            'audience' => $context->audience,
            'tone_notes' => $context->tone_notes,
            'hook_style' => $context->hook_style,
            'art_style' => $artStyle,
            'hook' => $hook,
            'viral_score' => (string) ($article->viral_score ?? 0),
            'total_parts' => (string) $totalParts,
            'facts_summary' => $factsSummary !== '' ? $factsSummary : '(no facts extracted)',
        ]);

        $response = $this->claude->generate($prompt, 'sonnet', $framework->system_prompt);
        $this->logVideoCost($article->id, 'story_planner', 'sonnet', $response);

        $parsed = $this->parseJson($response->text);
        if ($parsed === null || empty($parsed['parts_outline'])) {
            throw new \RuntimeException(
                "StoryPlanner: Claude returned unparseable/empty JSON for article {$article->id}."
            );
        }

        // Fallback anchor if Claude omits it -- keeps the pipeline from hard-failing
        // on this alone, but every scene's image_prompt loses cross-scene consistency
        // until an admin sets a real art_style/checks this article's output.
        $visualAnchor = trim($parsed['visual_anchor'] ?? '') ?: "Generic subject matching this topic, {$artStyle}, no logos or trademarked symbols.";

        return StoryPlan::create([
            'article_id' => $article->id,
            'hook' => $hook,
            'narrative_arc' => $parsed['narrative_arc'] ?? '',
            'mood' => $parsed['mood'] ?? 'epic',
            'visual_anchor' => $visualAnchor,
            'total_parts' => $totalParts,
            'parts_outline_json' => $parsed['parts_outline'],
        ]);
    }
}
