<?php

namespace App\Services\Admin\Concerns;

use App\Models\CategoryContext;
use App\Models\VideoClaudeCall;
use App\Services\Admin\ClaudeResponse;
use App\Services\Admin\ClaudeWriterService;
use Illuminate\Support\Facades\Log;

/**
 * Shared by FactExtractorService/StoryPlannerService/ScriptGeneratorService:
 * resolving the purpose=video CategoryContext+PromptFramework pair, extracting
 * a JSON object from Claude's raw text response, and logging cost into
 * video_claude_calls (deliberately NOT prompt_metrics/PromptMetric -- see the
 * 2026_06_20_000002 migration for why that table doesn't fit).
 *
 * Requires the using class to have a `private VideoPromptBuilderService
 * $promptBuilder` constructor-promoted property (all three callers do).
 */
trait VideoPipelineHelpers
{
    /** Fallback when Claude returns no visual_anchor — used in StoryPlanner and ScriptGenerator. */
    protected function defaultVisualAnchor(string $artStyle): string
    {
        return "Generic subject matching this topic, {$artStyle}, no logos or trademarked symbols.";
    }
    /**
     * Replaces the "$context = $this->promptBuilder->contextFor($id); $framework =
     * $context->videoFramework;" pair duplicated across all three services.
     * @return array{0: CategoryContext, 1: \App\Models\PromptFramework}
     */
    protected function resolveVideoFramework(string $categoryId): array
    {
        $context = $this->promptBuilder->contextFor($categoryId);

        return [$context, $context->videoFramework];
    }

    /** art_style from CategoryContext, falling back to the global config default. */
    protected function resolveArtStyle(CategoryContext $context): string
    {
        return $context->art_style ?: (string) config('video.default_art_style', '');
    }

    /** Returns null (not an exception) on empty/unparseable input -- callers
     * decide whether that means "escalate" or "fail this part, continue the rest". */
    protected function parseJson(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        // Claude is instructed to return raw JSON only, but defensively strip
        // markdown fences in case it adds them anyway. No /m flag -- this must
        // only strip the outermost fence (string start/end), same as
        // PostGuard's equivalent stripping, not every line that happens to
        // start/end with ``` (which would wrongly mangle fence-like content
        // inside a JSON string value).
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);

        $decoded = json_decode($text, true);
        return is_array($decoded) ? $decoded : null;
    }

    protected function logVideoCost(string $articleId, string $stage, string $modelType, ClaudeResponse $response): void
    {
        $cost = ClaudeWriterService::costUsd($response->inputTokens, $response->outputTokens, $modelType);

        try {
            VideoClaudeCall::create([
                'article_id' => $articleId,
                'stage' => $stage,
                'model_used' => $modelType,
                'input_tokens' => $response->inputTokens,
                'output_tokens' => $response->outputTokens,
                'cost_usd' => $cost,
            ]);
        } catch (\Throwable $e) {
            // Soft fail -- mirrors FeedbackService::record()'s own try/catch:
            // a cost-logging error must never break the pipeline itself.
            Log::warning('[VideoPipelineHelpers] Failed to log Claude cost', [
                'article_id' => $articleId,
                'stage' => $stage,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
