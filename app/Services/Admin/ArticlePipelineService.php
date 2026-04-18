<?php

namespace App\Services\Admin;

use App\Models\CategoryContext;
use App\Services\ArticleCleanerService;
use Illuminate\Support\Facades\Log;

/**
 * ArticlePipelineService — single entry point cho AI pipeline.
 *
 * Flow:
 *   Raw HTML → Clean → Haiku → HookEngine → PromptGuard
 *           → Sonnet (+ 1 retry on parse fail) → PostGuard → PipelineResult
 *
 * Caller (Controller / Job) giữ: persistence, status update, FeedbackService.
 */
class ArticlePipelineService
{
    public function __construct(
        private ArticleCleanerService $cleaner,
        private PromptBuilderService  $promptBuilder,
        private HookEngine            $hookEngine,
        private PromptGuard           $promptGuard,
        private PostGuard             $postGuard,
        private ClaudeWriterService   $claude,
    ) {}

    /**
     * @throws \RuntimeException  khi content quá ngắn, Haiku rỗng, PromptGuard fail,
     *                            hoặc JSON vẫn invalid sau retry
     */
    public function run(string $rawHtml, string $keyword, string $categoryId): PipelineResult
    {
        // ── 1. Clean + limit ──────────────────────────────────────────────────
        $originalLen = max(strlen($rawHtml), 1);
        $cleanedText = $this->cleaner->limit($this->cleaner->clean($rawHtml));

        if (strlen($cleanedText) < 100) {
            throw new \RuntimeException('Content qua ngan de xu ly');
        }

        $cleanerReductionRatio = round(1 - strlen($cleanedText) / $originalLen, 3);

        // ── 2. Build PromptPayload từ category DB ─────────────────────────────
        $payload      = $this->promptBuilder->build($categoryId);
        $context      = $categoryId ? CategoryContext::forCategory($categoryId) : null;
        $contentTypes = $context?->framework?->contentTypes ?? collect();
        $hookStyle    = $context?->hook_style ?? 'compelling and engaging opener';

        // ── 3. Haiku extract facts ─────────────────────────────────────────────
        $facts = $this->claude->generate($payload->haikuPrompt($cleanedText), 'haiku');

        if (empty(trim($facts))) {
            throw new \RuntimeException('Claude Haiku tra ve trong');
        }

        // ── 4. HookEngine → detect content type + best hook ───────────────────
        $hookResult        = $this->hookEngine->resolve($facts, $keyword, $hookStyle, $contentTypes);
        $typeModel         = $contentTypes->firstWhere('type_code', $hookResult->detectedType);
        $structureTemplate = $typeModel?->structure_template ?? config('prompt.default_structure', '');

        // ── 5. PromptGuard — chỉ validate hook; structureTemplate có fallback trong PromptPayload
        $this->promptGuard->validateHook($hookResult->bestHook);

        // ── 6. Sonnet + 1 retry only on parse fail ─────────────────────────────
        $sonnetPrompt = $payload->sonnetPrompt($facts, $hookResult->bestHook, $keyword, $structureTemplate);
        $sonnetRaw    = $this->claude->generate($sonnetPrompt, 'sonnet');
        $guardResult  = $this->postGuard->check($sonnetRaw, $facts);
        $retryCount   = 0;
        $retryReason  = null;

        if ($guardResult->isParseFailure()) {
            $retryCount  = 1;
            $retryReason = $guardResult->reason;
            Log::info('[Pipeline] Sonnet parse fail, retrying once', ['reason' => $retryReason]);
            $sonnetRaw   = $this->claude->generate($sonnetPrompt, 'sonnet');
            $guardResult = $this->postGuard->check($sonnetRaw, $facts);
        }

        if ($guardResult->isParseFailure()) {
            throw new \RuntimeException("Sonnet JSON invalid after retry: {$guardResult->reason}");
        }

        Log::debug('[Pipeline] Done', [
            'keyword'           => $keyword,
            'context_id'        => $context?->id,
            'hook_type'         => $hookResult->detectedType,
            'hook_score'        => $hookResult->bestScore,
            'guard_confidence'  => $guardResult->confidence,
            'retry_count'       => $retryCount,
        ]);

        return new PipelineResult(
            parsed:                $guardResult->parsed,
            hookResult:            $hookResult,
            context:               $context,
            guardResult:           $guardResult,
            retryCount:            $retryCount,
            retryReason:           $retryReason,
            schemaVersion:         $payload->schemaVersion(),
            promptFingerprint:     $payload->fingerprint(),
            cleanerReductionRatio: $cleanerReductionRatio,
            usedHaiku:             true,
        );
    }
}
