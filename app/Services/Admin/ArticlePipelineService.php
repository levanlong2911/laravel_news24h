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

        // ── 3. Haiku extract facts + hooks (1 call thay vì 2) ────────────────
        $haikuResp = $this->claude->generate(
            $payload->haikuCombinedPrompt($cleanedText, $keyword, $hookStyle),
            'haiku'
        );

        if (empty(trim($haikuResp->text))) {
            throw new \RuntimeException('Claude Haiku tra ve trong');
        }

        // Tách facts và hooks từ combined response
        $haikuText           = $haikuResp->text;
        $facts               = $haikuText;
        $preloadedCandidates = [];

        if (preg_match('/HOOKS_JSON:(\[.*?\])\s*$/s', $haikuText, $m)) {
            $decoded = json_decode($m[1], true);
            if (is_array($decoded) && count($decoded) >= 3) {
                $preloadedCandidates = array_values(array_filter($decoded, 'is_string'));
                $facts = trim(substr($haikuText, 0, strrpos($haikuText, 'HOOKS_JSON:')));
            }
        }

        if (strlen(trim($facts)) < 150) {
            throw new \RuntimeException('Haiku facts qua ngan (' . strlen(trim($facts)) . ' chars) — noi dung goc khong du thong tin');
        }

        // ── 4. HookEngine → detect content type + best hook ───────────────────
        $hookResult        = $this->hookEngine->resolve($facts, $keyword, $hookStyle, $contentTypes, $preloadedCandidates);
        $typeModel         = $contentTypes->firstWhere('type_code', $hookResult->detectedType);
        $structureTemplate = $typeModel?->structure_template ?? config('prompt.default_structure', '');

        // ── 5. PromptGuard — chỉ validate hook; structureTemplate có fallback trong PromptPayload
        $this->promptGuard->validateHook($hookResult->bestHook);

        // ── 6. Sonnet — retry với fix prompt thay vì lặp lại cùng prompt (tiết kiệm token)
        $sonnetPrompt = $payload->sonnetPrompt($facts, $hookResult->bestHook, $keyword, $structureTemplate);
        $sonnetResp   = $this->claude->generate($sonnetPrompt, 'sonnet', $payload->system);
        $guardResult  = $this->postGuard->check($sonnetResp->text, $facts);
        $retryCount   = 0;
        $retryReason  = null;
        $retryResp    = null;

        if ($guardResult->isParseFailure()) {
            $retryCount  = 1;
            $retryReason = $guardResult->reason;
            Log::info('[Pipeline] Sonnet parse fail, retrying with fix prompt', ['reason' => $retryReason]);

            $fixPrompt   = "The JSON below is invalid. String values contain literal \" characters that break JSON syntax.\n"
                . "Fix rule: every \" inside a string value must be escaped as \\\".\n"
                . "Return ONLY the corrected JSON — no markdown, no explanation.\n\n"
                . $sonnetResp->text;
            $retryResp   = $this->claude->generate($fixPrompt, 'sonnet');
            $guardResult = $this->postGuard->check($retryResp->text, $facts);
        }

        if ($guardResult->isParseFailure()) {
            throw new \RuntimeException("Sonnet JSON invalid after retry: {$guardResult->reason}");
        }

        // ── 7. Tính token usage + cost ────────────────────────────────────────
        $sonnetInputTokens  = $sonnetResp->inputTokens  + ($retryResp?->inputTokens  ?? 0);
        $sonnetOutputTokens = $sonnetResp->outputTokens + ($retryResp?->outputTokens ?? 0);
        $totalCostUsd       = ClaudeWriterService::costUsd($haikuResp->inputTokens, $haikuResp->outputTokens, 'haiku')
                            + ClaudeWriterService::costUsd($sonnetInputTokens, $sonnetOutputTokens, 'sonnet');

        Log::debug('[Pipeline] Done', [
            'keyword'            => $keyword,
            'context_id'         => $context?->id,
            'hook_type'          => $hookResult->detectedType,
            'hook_score'         => $hookResult->bestScore,
            'guard_confidence'   => $guardResult->confidence,
            'retry_count'        => $retryCount,
            'haiku_tokens'       => $haikuResp->inputTokens . '/' . $haikuResp->outputTokens,
            'sonnet_tokens'      => $sonnetInputTokens . '/' . $sonnetOutputTokens,
            'total_cost_usd'     => round($totalCostUsd, 6),
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
            haikuInputTokens:      $haikuResp->inputTokens,
            haikuOutputTokens:     $haikuResp->outputTokens,
            sonnetInputTokens:     $sonnetInputTokens,
            sonnetOutputTokens:    $sonnetOutputTokens,
            totalCostUsd:          $totalCostUsd,
        );
    }
}
