<?php

namespace App\Jobs;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Domain;
use App\Models\Post;
use App\Services\Admin\ClaudeWriterService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Nhận Article đã crawl (content thô) → Claude (Haiku + Sonnet) → lưu Post
 * Không crawl lại — dùng content sẵn có trong Article
 */
class WritePostFromArticleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 480;

    public function __construct(public readonly Article $article) {}

    public function handle(ClaudeWriterService $claude): void
    {
        $article = $this->article->fresh()->load('keyword');

        if ($article->status === 'processing') {
            Log::info("[WritePost] Already processing, skip: {$article->id}");
            return;
        }

        $article->update(['status' => 'processing']);

        $kwName  = $article->keyword->name ?? '';
        $rawText = $this->cleanForPrompt($article->content ?? '');

        Log::info("[WritePost] Start: {$kwName} | {$article->title}");

        try {
            if (strlen($rawText) < 100) {
                throw new \RuntimeException('Content quá ngắn để xử lý (<100 ký tự)');
            }

            // ── STEP 1: Claude Haiku — extract & structure key facts ──────────
            $facts = $claude->generate(
                $this->haikuPrompt($kwName, $rawText),
                'haiku'
            );

            if (empty(trim($facts))) {
                throw new \RuntimeException('Haiku returned empty');
            }

            // ── STEP 2: Claude Sonnet — write viral article → JSON ────────────
            $sonnetRaw = $claude->generate(
                $this->sonnetPrompt($kwName, $article->title, $facts),
                'sonnet'
            );

            $parsed = $this->parseJson($sonnetRaw);

            if (!$parsed) {
                $paragraphs = array_filter(explode("\n\n", trim($facts)));
                $parsed = [
                    'title'            => $article->title,
                    'meta_description' => Str::limit(strip_tags($facts), 155),
                    'content'          => implode('', array_map(fn($p) => "<p>{$p}</p>", $paragraphs)),
                ];
            }

            // ── STEP 3: Lưu vào Post ──────────────────────────────────────────
            $finalTitle = trim($parsed['title'] ?? $article->title);
            $slug       = $this->uniqueSlug(Str::slug($finalTitle ?: 'article'));

            $admin  = Cache::remember('default_admin',  3600, fn() => Admin::first());
            $domain = Cache::remember('default_domain', 3600, fn() => Domain::first());

            if (!$admin || !$domain) {
                throw new \RuntimeException('Không tìm thấy admin hoặc domain');
            }

            Post::create([
                'id'               => Str::uuid(),
                'title'            => $finalTitle,
                'meta_description' => Str::limit($parsed['meta_description'] ?? '', 255),
                'content'          => $parsed['content'] ?? '',
                'slug'             => $slug,
                'thumbnail'        => $article->thumbnail,
                'category_id'      => $article->keyword->category_id ?? null,
                'author_id'        => $admin->id,
                'domain_id'        => $domain->id,
            ]);

            // Đánh dấu Article đã xử lý xong
            $article->update(['status' => 'published', 'published_at' => now()]);

            Log::info("[WritePost] ✅ Published post: {$finalTitle}");

        } catch (\Throwable $e) {
            $article->update(['status' => 'failed']);
            Log::error("[WritePost] Failed [{$article->id}]: {$e->getMessage()}");
            throw $e;
        }
    }

    // ── HELPERS ───────────────────────────────────────────────────────────────

    private function cleanForPrompt(string $html): string
    {
        $text = preg_replace('/<\/?(p|h[1-6]|div|br|li)[^>]*>/i', "\n", $html);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $lines  = explode("\n", $text);
        $result = [];
        foreach ($lines as $line) {
            $t = trim($line);
            if ($t === '' || strlen($t) < 20) continue;
            $result[] = $t;
        }

        return implode("\n\n", $result);
    }

    private function haikuPrompt(string $keyword, string $content): string
    {
        return <<<PROMPT
You are an expert news fact extractor. Extract all newsworthy information from the article below and organize it in a clean structured format.

Topic: {$keyword}

EXTRACT AND ORGANIZE:
1. MAIN EVENT: What exactly happened? Who, when, where?
2. KEY PEOPLE: Full names, roles, organizations
3. EXACT QUOTES: Every direct quote with attribution
4. FACTS & NUMBERS: All statistics, dates, amounts
5. REACTIONS: What did people/experts say?
6. BACKGROUND: Relevant context
7. WHAT'S NEXT: Upcoming events, consequences

Raw article:
---
{$content}
---

Return ONLY structured facts. No editorializing. If a section has no data, write "N/A".
PROMPT;
    }

    private function sonnetPrompt(string $keyword, string $origTitle, string $facts): string
    {
        return <<<PROMPT
You are a viral news writer. Transform the extracted facts below into an original, engaging news article.

TOPIC: {$keyword}
ORIGINAL HEADLINE: {$origTitle}

EXTRACTED FACTS:
---
{$facts}
---

TITLE RULES: 60-70 characters. Factually accurate. Includes main keyword.
META DESCRIPTION: 150-160 characters. Lead with emotional hook or surprising fact.
CONTENT: 3,000-4,000 characters total. HTML format. 2 sentences per paragraph.

OUTPUT — Return ONLY this JSON (no markdown, no code block):
{
  "title": "...",
  "meta_description": "...",
  "content": "..."
}
PROMPT;
    }

    private function parseJson(string $raw): ?array
    {
        $clean = preg_replace('/^```(?:json)?\s*/m', '', $raw);
        $clean = preg_replace('/\s*```$/m', '', $clean);
        $clean = trim($clean);

        $decoded = json_decode($clean, true);
        if (json_last_error() === JSON_ERROR_NONE && !empty($decoded['content'])) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/s', $clean, $m)) {
            $decoded = json_decode($m[0], true);
            if (json_last_error() === JSON_ERROR_NONE && !empty($decoded['content'])) {
                return $decoded;
            }
        }

        return null;
    }

    private function uniqueSlug(string $base): string
    {
        $slug    = $base ?: 'article';
        $counter = 1;
        while (Post::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$counter}";
            $counter++;
        }
        return $slug;
    }

    public function failed(\Throwable $e): void
    {
        try {
            $this->article->update(['status' => 'failed']);
        } catch (\Throwable) {}
        Log::error('[WritePost] Permanently failed: ' . $e->getMessage());
    }
}
