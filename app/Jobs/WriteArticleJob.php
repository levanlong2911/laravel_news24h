<?php

namespace App\Jobs;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Domain;
use App\Models\Post;
use App\Models\RawArticle;
use App\Services\Admin\ArticleCrawlerService;
use App\Services\Admin\ArticlePipelineService;
use App\Services\Admin\FeedbackPayload;
use App\Services\Admin\FeedbackService;
use App\Services\Admin\PreGuard;
use App\Services\Admin\PreGuardException;
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
 * WriteArticleJob — orchestrate pipeline ngoài AI.
 *
 * Pipeline:
 *   Crawl → PRE Guard → Reserve slot → ArticlePipelineService → Save → Post
 *
 * AI pipeline (Haiku → HookEngine → Sonnet + retry → PostGuard) nằm trong ArticlePipelineService.
 *
 * Status lifecycle:
 *   processing → published  (ok, auto-publish)
 *   processing → review     (PostGuard low confidence | soft-duplicate)
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
        ArticleCrawlerService  $crawler,
        ArticlePipelineService $pipeline,
        PreGuard               $preGuard,
        FeedbackService        $feedbackService,
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

            // ── STEP 4: AI pipeline ───────────────────────────────────────
            $result     = $pipeline->run($rawText, $kwName, $categoryId ?? '');
            $parsed     = $result->parsed;
            $hookResult = $result->hookResult;
            $context    = $result->context;
            $needReview = $result->needsReview() || $softDuplicate;

            // ── STEP 5: Finalize & Save ───────────────────────────────────
            $finalTitle = $result->title() ?: $raw->title;
            $finalSlug  = $this->uniqueSlug(Str::slug($finalTitle ?: 'article'), $article->id);
            $faq        = $this->normalizeFaq($parsed['faq'] ?? []);

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

            // ── STEP 6: Create Post (chỉ khi không cần review) ───────────
            if (!$needReview) {
                $this->createPost($raw, $finalTitle, $finalSlug, $parsed);
            }

            // ── STEP 7: Record feedback metrics (chỉ khi có context) ─────
            if ($context) {
                $feedbackService->record(new FeedbackPayload(
                    contextId:             $context->id,
                    articleId:             $article->id,
                    contentTypeDetected:   $hookResult->detectedType,
                    hookScore:             $hookResult->bestScore,
                    hookRank:              $hookResult->hookRank,
                    hookCandidates:        count($hookResult->candidates),
                    guardConfidence:       $result->guardResult->confidence,
                    finalReason:           $result->guardResult->reason,
                    retryCount:            $result->retryCount,
                    retryReason:           $result->retryReason,
                    schemaVersion:         $result->schemaVersion,
                    promptFingerprint:     $result->promptFingerprint,
                    viralScore:            $raw->viral_score ?? 0,
                    wordCount:             str_word_count(strip_tags($parsed['content'] ?? '')),
                    processingTimeMs:      (int) round(microtime(true) * 1000) - $startMs,
                    needsReview:           $needReview,
                    cleanerReductionRatio: $result->cleanerReductionRatio,
                    usedHaiku:             $result->usedHaiku,
                ));
            }

            Log::info('[WriteArticle] Done', [
                'status'             => $needReview ? 'review' : 'published',
                'article_id'         => $article->id,
                'keyword'            => $kwName,
                'context_id'         => $context?->id,
                'prompt_fingerprint' => $result->promptFingerprint,
                'content_type'       => $hookResult->detectedType,
                'hook_used'          => $hookResult->bestHook,
                'hook_score'         => $hookResult->bestScore,
                'guard_confidence'   => $result->guardResult->confidence,
                'retry_count'        => $result->retryCount,
                'cleaner_ratio'      => $result->cleanerReductionRatio,
                'soft_duplicate'     => $softDuplicate,
                'viral_score'        => $raw->viral_score,
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
