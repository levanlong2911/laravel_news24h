<?php

namespace App\Services\Admin;

use App\Models\Article;
use App\Models\ArticleFact;
use App\Services\Admin\Concerns\VideoPipelineHelpers;
use Illuminate\Support\Facades\Log;

/**
 * Fact Extractor: Claude Haiku 4.5 by default, auto-escalates to Sonnet 4.6
 * if the response self-reports low/medium confidence or extracts too few
 * facts. Runs once per article -- output is reused by every Part and later
 * by the Phase 2 long-form script for the same article, never re-extracted
 * (idempotent: returns the existing row if one already exists).
 */
class FactExtractorService
{
    use VideoPipelineHelpers;

    private const MIN_FACTS_BEFORE_ESCALATION = 3;

    public function __construct(
        private ClaudeWriterService $claude,
        private VideoPromptBuilderService $promptBuilder,
    ) {
    }

    // Long list articles (50 yachts, 100 cars, etc.) cause Claude to generate
    // thousands of individual fact entries that exceed any max_tokens limit and
    // produce truncated (unparseable) JSON. Cap the content so the response
    // stays within a single model call — we only need 5-10 facts for a 15s video.
    private const MAX_CONTENT_CHARS = 4500;

    public function run(Article $article): ArticleFact
    {
        $existing = ArticleFact::where('article_id', $article->id)->first();
        if ($existing) {
            return $existing;
        }

        [$context, $framework] = $this->resolveVideoFramework($article->category_id);

        $content = $article->content;
        if (mb_strlen($content) > self::MAX_CONTENT_CHARS) {
            $content = mb_substr($content, 0, self::MAX_CONTENT_CHARS) . "\n\n[Content truncated — extract facts from the above section only.]";
        }

        $prompt = $this->promptBuilder->inject($framework->phase1_analyze, [
            'domain' => $context->domain,
            'audience' => $context->audience,
            'terminology' => implode(', ', $context->terminology ?? []),
            'article_title' => $article->title,
            'article_content' => $content,
        ]);

        $response = $this->claude->generate($prompt, 'haiku', $framework->system_prompt);
        $this->logVideoCost($article->id, 'fact_extractor', 'haiku', $response);

        $parsed = $this->parseJson($response->text);
        $escalated = false;

        $needsEscalation = $parsed === null
            || in_array($parsed['confidence'] ?? 'low', ['low', 'medium'], true)
            || count($parsed['facts'] ?? []) < self::MIN_FACTS_BEFORE_ESCALATION;

        if ($needsEscalation) {
            Log::info('[FactExtractor] Escalating to Sonnet', [
                'article_id' => $article->id,
                'haiku_confidence' => $parsed['confidence'] ?? null,
                'haiku_fact_count' => count($parsed['facts'] ?? []),
            ]);

            $sonnetResponse = $this->claude->generate($prompt, 'sonnet', $framework->system_prompt);
            $this->logVideoCost($article->id, 'fact_extractor', 'sonnet', $sonnetResponse);

            $sonnetParsed = $this->parseJson($sonnetResponse->text);
            if ($sonnetParsed !== null) {
                $parsed = $sonnetParsed;
                $escalated = true;
            }
            // If Sonnet also failed to parse, keep whatever Haiku produced (even if
            // thin) rather than having nothing at all -- the caller can still see
            // confidence=low and escalated_to_sonnet=true as a quality signal.
        }

        if ($parsed === null) {
            throw new \RuntimeException(
                "FactExtractor: Claude returned unparseable JSON for article {$article->id} "
                . "(both Haiku and Sonnet attempts failed)."
            );
        }

        if (empty($parsed['facts'])) {
            throw new \RuntimeException(
                "FactExtractor: article {$article->id} produced zero extractable facts "
                . "(confidence={$parsed['confidence']}). Article content may be too thin for video generation."
            );
        }

        return ArticleFact::create([
            'article_id' => $article->id,
            'facts_json' => $parsed['facts'] ?? [],
            'confidence' => $parsed['confidence'] ?? 'low',
            'escalated_to_sonnet' => $escalated,
            'entities_json' => $parsed['entities'] ?? null,
        ]);
    }
}
