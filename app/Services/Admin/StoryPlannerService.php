<?php

namespace App\Services\Admin;

use App\Models\Article;
use App\Models\ArticleFact;
use App\Models\StoryPlan;
use App\Models\VideoAnalytic;
use App\Services\Admin\Concerns\VideoPipelineHelpers;
use Illuminate\Support\Str;

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

    public function run(Article $article, ArticleFact $facts, int $totalParts = 1): StoryPlan
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

        $artStyle = $this->resolveArtStyle($context);
        $analyticsHint = $this->buildAnalyticsHint($article->category_id);

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
            'analytics_hint' => $analyticsHint,
        ]);

        $response = $this->claude->generate($prompt, 'sonnet', $framework->system_prompt);
        $this->logVideoCost($article->id, 'story_planner', 'sonnet', $response);

        $parsed = $this->parseJson($response->text);
        if ($parsed === null || empty($parsed['parts_outline'])) {
            throw new \RuntimeException(
                "StoryPlanner: Claude returned unparseable/empty JSON for article {$article->id}."
            );
        }

        $visualAnchor = trim($parsed['visual_anchor'] ?? '') ?: "Generic subject matching this topic, {$artStyle}, no logos or trademarked symbols.";

        $narrativeArc = $parsed['narrative_arc'] ?? '';
        $contentType = $this->resolveContentType($narrativeArc, $context->domain);

        return StoryPlan::create([
            'article_id' => $article->id,
            'hook' => $hook,
            'narrative_arc' => $narrativeArc,
            'mood' => $parsed['mood'] ?? 'epic',
            'content_type' => $contentType,
            'visual_anchor' => $visualAnchor,
            'total_parts' => $totalParts,
            'parts_outline_json' => $parsed['parts_outline'],
        ]);
    }

    /**
     * L12 — Analytics Feedback Loop.
     * Fetches top-3 performing narrative patterns in the same category
     * (by avg CTR over the last 30 days) and returns a hint string to inject
     * into the StoryPlanner prompt so Claude can bias toward proven structures.
     * Returns empty string when no analytics data exists yet (first-run safe).
     */
    private function buildAnalyticsHint(string $categoryId): string
    {
        $top = VideoAnalytic::selectRaw(
                'story_plans.narrative_arc,
                 story_plans.mood,
                 story_plans.hook,
                 AVG(video_analytics.ctr) as avg_ctr,
                 AVG(video_analytics.retention_rate) as avg_retention'
            )
            ->join('video_jobs', 'video_jobs.id', '=', 'video_analytics.video_job_id')
            ->join('story_plans', 'story_plans.id', '=', 'video_jobs.story_plan_id')
            ->join('articles', 'articles.id', '=', 'story_plans.article_id')
            ->where('articles.category_id', $categoryId)
            ->where('video_analytics.date', '>=', now()->subDays(30))
            ->whereNotNull('video_analytics.ctr')
            ->groupBy('story_plans.id', 'story_plans.narrative_arc', 'story_plans.mood', 'story_plans.hook')
            ->orderByDesc('avg_ctr')
            ->limit(3)
            ->get();

        if ($top->isEmpty()) {
            return '(no analytics data yet — use your best judgement)';
        }

        $lines = $top->map(fn ($row) =>
            sprintf('- Hook: "%s" | Mood: %s | CTR: %.1f%% | Retention: %.0f%%',
                Str::limit($row->hook, 60),
                $row->mood,
                ($row->avg_ctr ?? 0) * 100,
                ($row->avg_retention ?? 0) * 100,
            )
        )->implode("\n");

        return "Top-performing patterns from this category (last 30 days):\n{$lines}\n"
            . "Bias your narrative arc and mood toward similar patterns when relevant.";
    }

    private function resolveContentType(string $narrativeArc, string $domain): string
    {
        $visualKeywords = ['yacht', 'superyacht', 'construction', 'travel', 'luxury', 'architecture', 'scenic'];
        $text = strtolower($narrativeArc . ' ' . $domain);

        foreach ($visualKeywords as $keyword) {
            if (str_contains($text, $keyword)) {
                return 'visual_image';
            }
        }

        return 'informational';
    }
}
