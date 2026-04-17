<?php

namespace App\Jobs;

use App\Models\Admin;
use App\Models\Article;
use App\Models\CategoryContext;
use App\Models\Domain;
use App\Models\Post;
use App\Models\RawArticle;
use App\Services\Admin\ArticleCrawlerService;
use App\Services\Admin\ClaudeWriterService;
use App\Services\Admin\FeedbackPayload;
use App\Services\Admin\FeedbackService;
use App\Services\Admin\HookEngine;
use App\Services\Admin\PostGuard;
use App\Services\Admin\PreGuard;
use App\Services\Admin\PromptGuard;
use App\Services\Admin\PreGuardException;
use App\Services\Admin\PromptBuilderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * WriteArticleJob — orchestrate toàn bộ AI pipeline.
 *
 * Pipeline:
 *   Crawl → PRE Guard → Reserve slot → PromptBuilder
 *         → Haiku → HookEngine → Sonnet (+ 1 retry) → POST Guard → Save → Post
 *
 * Status lifecycle:
 *   processing → published  (ok, auto-publish)
 *   processing → review     (PostGuard low confidence | soft-duplicate | parse fail)
 *   processing → failed     (exception)
 */
class WriteArticleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 1;
    public int $timeout = 480;

    public function __construct(public readonly RawArticle $rawArticle) {}

    // ── Main pipeline ─────────────────────────────────────────────────────────

    public function handle(
        ArticleCrawlerService $crawler,
        ClaudeWriterService   $claude,
        PreGuard              $preGuard,
        PromptBuilderService  $promptBuilder,
        HookEngine            $hookEngine,
        PromptGuard           $promptGuard,
        PostGuard             $postGuard,
        FeedbackService       $feedbackService,
    ): void {
        $raw = $this->rawArticle->fresh()->load('keyword');

        if (in_array($raw->status, ['generating', 'done'])) {
            Log::info("[WriteArticle] Already {$raw->status}, skip: {$raw->url}");
            return;
        }

        $raw->update(['status' => 'generating']);

        $startMs    = (int) round(microtime(true) * 1000);
        $url        = $raw->url;
        $kwName     = $raw->keyword->name;
        $categoryId = $raw->keyword->category_id ?? null;

        Log::info("[WriteArticle] Start: {$kwName} | {$raw->title}");

        $article = null;

        try {
            // ── STEP 1: Crawl ─────────────────────────────────────────────
            $crawled = $crawler->crawlMany([$url]);
            $rawText = trim($crawled[$url] ?? '');

            if (strlen($rawText) < 200) {
                Log::info("[WriteArticle] Crawl short, fallback to snippet: {$url}");
                $rawText = $raw->snippet ?? '';
            }

            // ── STEP 2: PRE Guard ─────────────────────────────────────────
            $softDuplicate = false;

            try {
                $preGuard->check($rawText, $kwName);
            } catch (PreGuardException $e) {
                if ($e->isSoftDuplicate()) {
                    // Fix 3: soft-dup → tiếp tục nhưng bắt buộc review
                    $softDuplicate = true;
                    Log::info("[WriteArticle] Soft-duplicate, continuing with review flag: {$e->getMessage()}");
                } elseif ($e->isDuplicate()) {
                    $raw->update(['status' => 'done']);
                    Log::info("[WriteArticle] PRE Guard [{$e->reason}] skip: {$e->getMessage()}");
                    return;
                } else {
                    $raw->update(['status' => 'failed']);
                    Log::info("[WriteArticle] PRE Guard [{$e->reason}] skip: {$e->getMessage()}");
                    return;
                }
            }

            $contentHash    = $preGuard->hashContent($rawText);
            $contentSimhash = $preGuard->simhashContent($rawText);

            // ── STEP 3: Reserve article slot ──────────────────────────────
            try {
                $article = Article::create([
                    'keyword_id'      => $raw->keyword_id,
                    'category_id'     => $categoryId,
                    'source_url'      => $url,
                    'source_url_hash' => md5($url),
                    'source_title'    => $raw->title,
                    'source_name'     => $raw->source,
                    'thumbnail'       => $raw->thumbnail,
                    'title'           => $raw->title,
                    'slug'            => $this->uniqueSlug(Str::slug($raw->title ?: 'article')),
                    'content'         => $raw->snippet ?? '',
                    'content_hash'    => $contentHash,
                    'content_simhash' => $contentSimhash,
                    'viral_score'     => $raw->viral_score,
                    'status'          => 'processing',
                    'human_review'    => false,
                    'expires_at'      => now()->addHours(48),
                ]);
            } catch (UniqueConstraintViolationException) {
                Log::info('[WriteArticle] Race condition duplicate on insert, skipping');
                $raw->update(['status' => 'done']);
                return;
            }

            // ── STEP 4: Build PromptPayload ───────────────────────────────
            $payload      = $promptBuilder->build($categoryId ?? '');
            $context      = $categoryId ? CategoryContext::forCategory($categoryId) : null;
            $contentTypes = $context?->framework?->contentTypes ?? collect();
            $hookStyle    = $context?->hook_style ?? 'compelling and engaging opener';

            // ── STEP 5: Clean + Haiku ─────────────────────────────────────
            $cleanedText = $this->cleanForPrompt($rawText);
            $facts       = $claude->generate($payload->haikuPrompt($cleanedText), 'haiku');

            if (empty(trim($facts))) {
                throw new \RuntimeException('Haiku returned empty facts');
            }

            // ── STEP 6: HookEngine ────────────────────────────────────────
            $hookResult = $hookEngine->resolve($facts, $kwName, $hookStyle, $contentTypes);

            // Load structure_template for the detected content type
            // Falls back to config default inside sonnetPrompt() if empty
            $typeModel         = $contentTypes->firstWhere('type_code', $hookResult->detectedType);
            $structureTemplate = $typeModel?->structure_template
                ?? config('prompt.default_structure', '');

            // ── STEP 6b: PromptGuard — validate pre-conditions before Sonnet ──
            // Throws PromptGuardException if hook or structureTemplate is missing.
            // Caught by the outer \Throwable handler → article marked failed.
            $promptGuard->validate($hookResult->bestHook, $structureTemplate);

            // ── STEP 7: Sonnet + 1 retry CHỈ khi parse fail ─────────────
            // Retry khi: JSON invalid / missing required fields (lỗi ngẫu nhiên)
            // KHÔNG retry khi: hallucination confidence thấp → retrying không giúp gì
            $sonnetPrompt = $payload->sonnetPrompt($facts, $hookResult->bestHook, $kwName, $structureTemplate);
            $sonnetRaw    = $claude->generate($sonnetPrompt, 'sonnet');
            $guardResult  = $postGuard->check($sonnetRaw, $facts);
            $retryCount   = 0;
            $retryReason  = null;

            if ($guardResult->isParseFailure()) {
                $retryCount  = 1;
                $retryReason = $guardResult->reason; // 'json_invalid' | 'missing_fields'
                Log::info('[WriteArticle] Sonnet parse fail (not hallucination), retrying once...', [
                    'retry_reason' => $retryReason,
                ]);
                $sonnetRaw   = $claude->generate($sonnetPrompt, 'sonnet');
                $guardResult = $postGuard->check($sonnetRaw, $facts);
            }

            $finalReason = $guardResult->reason; // reason sau lần check cuối cùng

            // ── STEP 8: POST Guard result → decide status ─────────────────
            $needReview = $guardResult->needsHumanReview() || $softDuplicate;

            // Fix 1: JSON fail sau retry → đánh dấu review, KHÔNG fallback HTML
            if ($guardResult->isParseFailure()) {
                Log::warning("[WriteArticle] Sonnet parse fail after retry, marking review: {$url}");
                $article->update([
                    'status'       => 'review',
                    'human_review' => true,
                    'hook_type'    => $hookResult->detectedType,
                    'hook_score'   => $hookResult->bestScore,
                    'hook_rank'    => $hookResult->hookRank,
                ]);
                $raw->update(['status' => 'done', 'article_id' => $article->id]);
                return;
            }

            $parsed = $guardResult->parsed;

            // ── STEP 9: Finalize & Save ───────────────────────────────────
            $finalTitle = trim($parsed['title'] ?? $hookResult->bestHook ?: $raw->title);
            $finalSlug  = $this->uniqueSlug(Str::slug($finalTitle ?: 'article'), $article->id);
            $faq        = $this->normalizeFaq($parsed['faq'] ?? []);

            // Fix 4: status = 'review' khi cần duyệt, 'published' khi ok
            $article->update([
                'title'            => $finalTitle,
                'slug'             => $finalSlug,
                'meta_description' => Str::limit($parsed['meta_description'] ?? '', 255),
                'summary'          => $parsed['summary'] ?? '',
                'content'          => $parsed['content'] ?? '',
                'faq'              => $faq,
                'status'           => $needReview ? 'review' : 'published',
                'human_review'     => $needReview,
                'hook_type'        => $hookResult->detectedType,
                'hook_score'       => $hookResult->bestScore,
                'hook_rank'        => $hookResult->hookRank,
                'published_at'     => $needReview ? null : now(),
            ]);

            $raw->update(['status' => 'done', 'article_id' => $article->id]);

            // ── STEP 10: Create Post (chỉ khi không cần review) ──────────
            if (!$needReview) {
                $this->createPost($raw, $finalTitle, $finalSlug, $parsed);
            }

            // ── STEP 11: Record feedback metrics (chỉ khi có context) ────
            if ($context) {
                $feedbackService->record(new FeedbackPayload(
                    contextId:          $context->id,
                    articleId:          $article->id,
                    contentTypeDetected: $hookResult->detectedType,
                    hookScore:          $hookResult->bestScore,
                    hookRank:           $hookResult->hookRank,
                    hookCandidates:     count($hookResult->candidates),
                    guardConfidence:    $guardResult->confidence,
                    finalReason:        $finalReason,
                    retryCount:         $retryCount,
                    retryReason:        $retryReason,
                    schemaVersion:      $payload->schemaVersion(),
                    promptFingerprint:  $payload->fingerprint(),
                    viralScore:         $raw->viral_score ?? 0,
                    wordCount:          str_word_count(strip_tags($parsed['content'] ?? '')),
                    processingTimeMs:   (int) round(microtime(true) * 1000) - $startMs,
                    needsReview:        $needReview,
                ));
            }

            // Fix 1: fingerprint log — trace ngược nhanh khi debug production
            Log::info('[WriteArticle] Pipeline fingerprint', [
                'status'            => $needReview ? 'review' : 'published',
                'article_id'        => $article->id,
                'keyword'           => $kwName,
                'context_id'        => $context?->id,
                'framework'         => $context?->framework?->name,
                'prompt_fingerprint'=> $payload->fingerprint(),
                'schema_version'    => $payload->schemaVersion(),
                'content_type'      => $hookResult->detectedType,
                'hook_used'         => $hookResult->bestHook,
                'hook_score'        => $hookResult->bestScore,
                'hook_rank'         => $hookResult->hookRank,
                'hook_candidates'   => count($hookResult->candidates),
                'guard_confidence'  => $guardResult->confidence,
                'retry_count'       => $retryCount,
                'retry_reason'      => $retryReason,   // reason lần check đầu (null nếu không retry)
                'final_reason'      => $finalReason,   // reason lần check cuối (biết retry có cứu được không)
                'soft_duplicate'    => $softDuplicate,
                'content_chars'     => strlen($parsed['content'] ?? ''),
                'faq_count'         => count($faq),
                'viral_score'       => $raw->viral_score,
            ]);

        } catch (\Throwable $e) {
            $raw->update(['status' => 'failed']);
            $article?->update(['status' => 'failed']);
            Log::error("[WriteArticle] Failed [{$url}]: {$e->getMessage()}", [
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    // ── Private — Content Cleaning ────────────────────────────────────────────

    private function cleanForPrompt(string $html): string
    {
        $text = preg_replace('/<\/?(p|h[1-6]|div|br|li)[^>]*>/i', "\n", $html);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $lines  = explode("\n", $text);
        $result = [];

        foreach ($lines as $line) {
            $t = trim($line);
            if ($t === '') continue;

            if (preg_match('/^All comments are subject to/i', $t)) break;
            if (preg_match('/^[*\-]\s+/', $t)) continue;
            if (strlen($t) < 20) continue;
            if (preg_match('/^Never miss a story/i', $t)) continue;
            if (preg_match('/^(Get more in our free app|Download the app)\s*$/i', $t)) continue;
            if (preg_match('/\bis (?:a|an) .{2,40}(?:Writer|Editor|Reporter|Correspondent|Contributor) at /i', $t)) continue;
            if (preg_match('/^NEED TO KNOW\s*$/i', $t)) continue;

            $result[] = $t;
        }

        return implode("\n\n", $result);
    }

    // ── Private — Post Creation ───────────────────────────────────────────────

    private function createPost(RawArticle $raw, string $title, string $slug, array $parsed): void
    {
        try {
            $admin  = Cache::remember('default_admin',  3600, fn() => Admin::first());
            $domain = Cache::remember('default_domain', 3600, fn() => Domain::first());

            if (!$admin || !$domain) {
                Log::warning('[WriteArticle] Skipping Post creation: no admin or domain found');
                return;
            }

            // Fix 5: idempotency — nếu Post với slug này đã tồn tại → bài đã được tạo (job retry)
            if (Post::where('slug', $slug)->where('domain_id', $domain->id)->exists()) {
                Log::info("[WriteArticle] Post already exists (slug: {$slug}), skipping");
                return;
            }

            $postSlug = $slug;
            $counter  = 1;
            while (Post::where('slug', $postSlug)->where('domain_id', $domain->id)->exists()) {
                $postSlug = $slug . '-' . $counter++;
            }

            Post::create([
                'id'               => Str::uuid(),
                'title'            => $title,
                'meta_description' => Str::limit($parsed['meta_description'] ?? '', 255),
                'content'          => $parsed['content'] ?? '',
                'slug'             => $postSlug,
                'thumbnail'        => $raw->thumbnail,
                'category_id'      => $raw->keyword->category_id ?? null,
                'author_id'        => $admin->id,
                'domain_id'        => $domain->id,
                'fb_image_text'    => $parsed['fb_image_text'] ?? null,
                'fb_quote'         => $parsed['fb_quote']      ?: null, // empty string → null
                'fb_post_content'  => $parsed['fb_post_content'] ?? null,
            ]);

            Log::info("[WriteArticle] Post created: {$title}");
        } catch (\Throwable $e) {
            Log::warning("[WriteArticle] Post creation skipped: {$e->getMessage()}");
        }
    }

    // ── Private — Helpers ─────────────────────────────────────────────────────

    private function normalizeFaq(mixed $faq): array
    {
        if (!is_array($faq)) return [];
        return array_values(array_filter(
            $faq,
            fn($i) => isset($i['question'], $i['answer'])
                   && !empty(trim($i['question']))
                   && !empty(trim($i['answer']))
        ));
    }

    private function uniqueSlug(string $base, ?string $excludeId = null): string
    {
        $slug    = $base ?: 'article';
        $counter = 1;
        while (
            Article::where('slug', $slug)
                ->when($excludeId, fn($q) => $q->where('id', '!=', $excludeId))
                ->exists()
        ) {
            $slug = "{$base}-{$counter}";
            $counter++;
        }
        return $slug;
    }

    // ── Job failure hook ──────────────────────────────────────────────────────

    public function failed(\Throwable $e): void
    {
        try {
            $this->rawArticle->update(['status' => 'failed']);
            Article::where('source_url_hash', md5($this->rawArticle->url))
                ->where('status', 'processing')
                ->update(['status' => 'failed']);
        } catch (\Throwable) {}

        Log::error('[WriteArticle] Permanently failed: ' . $e->getMessage());
    }
}
