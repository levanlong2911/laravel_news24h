<?php

namespace App\Jobs;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Domain;
use App\Models\Post;
use App\Models\RawArticle;
use App\Services\Admin\ArticleCrawlerService;
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
 * BƯỚC 2: Viết bài từ 1 RawArticle (do user nhấn "Generate").
 * Pipeline: Crawl → Haiku (extract facts) → Sonnet (write viral article) → Save
 */
class WriteArticleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 480; // 8 phút cho full pipeline + retry delay

    public function __construct(public readonly RawArticle $rawArticle) {}

    public function handle(ArticleCrawlerService $crawler, ClaudeWriterService $claude): void
    {
        $raw = $this->rawArticle->fresh()->load('keyword');

        // Guard: tránh chạy lại nếu đã xử lý
        if (in_array($raw->status, ['generating', 'done'])) {
            Log::info("[WriteArticle] Already {$raw->status}, skip: {$raw->url}");
            return;
        }

        $raw->update(['status' => 'generating']);

        $url    = $raw->url;
        $kwName = $raw->keyword->name;

        Log::info("[WriteArticle] Start: {$kwName} | {$raw->title}");

        // Tạo article record giữ slot (tránh race condition)
        $article = Article::create([
            'keyword_id'      => $raw->keyword_id,
            'source_url'      => $url,
            'source_url_hash' => md5($url),
            'source_title'    => $raw->title,
            'source_name'     => $raw->source,
            'thumbnail'       => $raw->thumbnail,
            'title'           => $raw->title,
            'slug'            => $this->uniqueSlug(Str::slug($raw->title ?: 'article')),
            'content'         => $raw->snippet ?? '',
            'viral_score'     => $raw->viral_score,
            'status'          => 'processing',
            'expires_at'      => now()->addHours(48),
        ]);

        try {
            // ── STEP 1: Crawl full content ─────────────────────────────────────
            $crawled = $crawler->crawlMany([$url]);
            $rawText = trim($crawled[$url] ?? '');

            if (strlen($rawText) < 200) {
                Log::info("[WriteArticle] Crawl short, fallback to snippet: {$url}");
                $rawText = $raw->snippet ?? '';
            }

            if (empty($rawText)) {
                throw new \RuntimeException('No content (crawl + snippet both empty)');
            }

            // ── STEP 2: Claude Haiku — extract & structure key facts ──────────
            $facts = $claude->generate(
                $this->haikuPrompt($kwName, $rawText),
                'haiku'
            );

            if (empty(trim($facts))) {
                throw new \RuntimeException('Haiku returned empty');
            }

            // ── STEP 3: Claude Sonnet — write viral article → JSON ────────────
            $sonnetRaw = $claude->generate(
                $this->sonnetPrompt($kwName, $raw->title, $facts),
                'sonnet'
            );

            $parsed = $this->parseJson($sonnetRaw);

            if (!$parsed) {
                // Fallback: wrap Haiku output với cấu trúc cơ bản
                Log::warning("[WriteArticle] Sonnet JSON failed, fallback: {$url}");
                $paragraphs = array_filter(explode("\n\n", trim($facts)));
                $parsed = [
                    'title'            => $raw->title,
                    'meta_description' => Str::limit(strip_tags($facts), 155),
                    'summary'          => Str::limit($facts, 280),
                    'content'          => implode('', array_map(fn($p) => "<p>{$p}</p>", $paragraphs)),
                    'faq'              => [],
                ];
            }

            // ── STEP 4: Finalize & Save ────────────────────────────────────────
            $finalTitle = trim($parsed['title'] ?? $raw->title);
            $finalSlug  = $this->uniqueSlug(Str::slug($finalTitle ?: 'article'), $article->id);
            $faq        = $this->normalizeFaq($parsed['faq'] ?? []);

            $article->update([
                'title'            => $finalTitle,
                'slug'             => $finalSlug,
                'meta_description' => Str::limit($parsed['meta_description'] ?? '', 255),
                'summary'          => $parsed['summary'] ?? '',
                'content'          => $parsed['content'] ?? '',
                'faq'              => $faq,
                'status'           => 'published',
                'published_at'     => now(),
            ]);

            // Mark raw article done + link
            $raw->update([
                'status'     => 'done',
                'article_id' => $article->id,
            ]);

            // ── Tạo Post record ──────────────────────────────────────────────
            $this->createPost($raw, $article, $finalTitle, $finalSlug, $parsed);

            Log::info(sprintf(
                '[WriteArticle] ✅ Published [score:%d] [chars:%d] [faq:%d] — %s',
                $raw->viral_score,
                strlen($parsed['content'] ?? ''),
                count($faq),
                $finalTitle
            ));

        } catch (\Throwable $e) {
            $raw->update(['status' => 'failed']);
            $article->update(['status' => 'failed']);
            Log::error("[WriteArticle] Failed [{$url}]: {$e->getMessage()}");
            throw $e;
        }
    }

    // ═════════════════════════════════════════════════════════════════════════
    // PROMPTS
    // ═════════════════════════════════════════════════════════════════════════

    private function haikuPrompt(string $keyword, string $content): string
    {
        return <<<PROMPT
You are an expert news fact extractor for a major US publication. Extract all newsworthy information from the raw article below and organize it in a clean structured format.

Topic: {$keyword}

EXTRACT AND ORGANIZE (keep EVERY specific detail — never invent or change facts):

1. MAIN EVENT: What exactly happened? Who did what, when, where? (be specific)
2. KEY PEOPLE: Full names, roles/titles, teams/organizations
3. EXACT QUOTES: Every direct quote with exact attribution ("quote" — Name, Title)
4. FACTS & NUMBERS: All statistics, scores, dates, dollar amounts, percentages (exact)
5. REACTIONS: What did people/fans/experts say or do in response?
6. BACKGROUND: Relevant context that explains why this matters
7. WHAT'S NEXT: Upcoming events, decisions, consequences, open questions

Raw article:
---
{$content}
---

Return ONLY the structured facts above. No editorializing. No invented details. If a section has no data, write "N/A".
PROMPT;
    }

    private function sonnetPrompt(string $keyword, string $origTitle, string $facts): string
    {
        return <<<PROMPT
You are a viral news writer with 15 years of experience at top US digital publications (New York Post, Daily Beast, BuzzFeed News). Your articles get millions of shares because you understand human psychology — what makes people feel something, react, and share with friends.

MISSION: Transform the extracted facts below into a completely original, deeply humanized news article. It must read like a real beat journalist wrote it. It must make readers feel something and share immediately. Facebook must see it as 100% original content.

TOPIC: {$keyword}
ORIGINAL HEADLINE: {$origTitle}

EXTRACTED FACTS:
---
{$facts}
---

══════════════════════════════════════════════════════════════
TITLE — Pick the ONE formula that creates the strongest emotional pull:
══════════════════════════════════════════════════════════════
▸ [SHOCK]       "Nobody Expected [Specific Person] To [Specific Action] — Here's What Really Happened"
▸ [CONTROVERSY] "[Person/Team] Just Made a [Decision] That Has [Fans/Experts] Completely Divided"
▸ [BETRAYAL]    "The Real Story Behind [Event] That Changes How You See [Person/Team]"
▸ [STAKES]      "Why [Person]'s [Decision] Could Be the Biggest Mistake of Their Career"
▸ [EMOTION]     "[Person] Finally Breaks Silence on [Topic] — And the Truth Is [Shocking/Heartbreaking]"
▸ [URGENCY]     "[Specific Event] Just Happened and Every [Fan/Reader] Needs to Know This Now"

TITLE RULES: 60-70 characters. Factually 100% accurate. Includes main keyword naturally. No ellipsis "...".

══════════════════════════════════════════════════════════════
META DESCRIPTION
══════════════════════════════════════════════════════════════
Lead with the emotional hook or the most surprising fact. Create FOMO — readers must feel they'll miss out if they don't click. Exactly 150-160 characters.

══════════════════════════════════════════════════════════════
CONTENT REQUIREMENTS (READ EVERY RULE)
══════════════════════════════════════════════════════════════
LENGTH: 3,500–4,000 characters total (count including HTML tags)
PARAGRAPH RULE: EXACTLY 2 sentences per paragraph. Every single paragraph. No exceptions.
VOICE: Like a knowledgeable, passionate friend texting you the most insane news right now
PERSPECTIVE: Use "you" and "we" to pull readers in personally
EMOTION RULE: Every paragraph must trigger one emotion — shock, outrage, pride, disbelief, excitement, or hope
FACTS: Use every specific number, name, date, quote from the extracted facts. Never invent data.
TRANSITIONS: Natural, conversational — "But here's where it gets wild.", "And then it happened.", "Nobody saw this coming.", "Here's the part that will surprise you."
SENTENCE VARIETY: Mix punchy 5-word sentences with longer explanatory ones. Never 3 long sentences in a row.

BANNED WORDS (never use): delve, crucial, it's worth noting, robust, multifaceted, tapestry, pivotal, paramount, groundbreaking, game-changer, leverage, utilize, in conclusion, moreover, furthermore, nevertheless, thus, hence

CONTENT STRUCTURE (HTML):
<p>[OPENING HOOK: Drop the reader into the most dramatic/emotional moment first. Make them feel it in exactly 2 sentences. No slow buildup — start at the peak of the action.]</p>

<p>[THE FACTS: Who is involved, what exactly happened, when and where. Specific names, dates, numbers in 2 punchy sentences.]</p>

<h2>[Controversy/stakes/emotional angle as a bold statement or provocative question — not generic]</h2>

<p>[The heart of the story: what makes this surprising, controversial, or outrageous. 2 sentences.]</p>

<p>[Real quote from the facts with exact attribution, OR the strongest fan/expert reaction. 2 sentences.]</p>

<p>[Deeper context: why this specific development matters beyond the surface. 2 sentences.]</p>

<p>[A detail or angle that most people haven't considered — the "wait, really?" moment. 2 sentences.]</p>

<h2>[Why This Hits Different — a header that speaks directly to what the reader is feeling]</h2>

<p>[The emotional angle: what fans/people feel about this and the deeper reason it resonates. 2 sentences.]</p>

<p>[Historical comparison or parallel that makes this feel bigger than it is. 2 sentences.]</p>

<p>[What the numbers/data reveal that isn't obvious from the headline. 2 sentences.]</p>

<h2>[What Happens Next / The Stakes Going Forward — specific, not generic]</h2>

<p>[Concrete next steps, upcoming events, or decisions that will determine what happens. 2 sentences.]</p>

<p>[Bold but fact-based prediction or what insiders are saying about the outcome. 2 sentences.]</p>

<p>[CLOSER: One unforgettable 2-sentence ending that leaves the reader thinking, talking, or sharing. Make it land hard.]</p>

══════════════════════════════════════════════════════════════
SUMMARY (for social media / email newsletter teaser)
══════════════════════════════════════════════════════════════
Exactly 3 sentences:
• Sentence 1: The hook that makes people stop scrolling (most shocking element)
• Sentence 2: The key fact they can't believe
• Sentence 3: The emotional consequence or open question that drives clicks

══════════════════════════════════════════════════════════════
FAQ (3 questions people are actually Googling about this story)
══════════════════════════════════════════════════════════════
Use natural search language (how people actually type into Google).
Each answer: 2-3 direct sentences from the article facts only — never invent.

══════════════════════════════════════════════════════════════
OUTPUT — Return ONLY this JSON (no markdown, no code block, no text before or after):
══════════════════════════════════════════════════════════════
{
  "title": "...",
  "meta_description": "...",
  "summary": "...",
  "content": "...",
  "faq": [
    {"question": "...", "answer": "..."},
    {"question": "...", "answer": "..."},
    {"question": "...", "answer": "..."}
  ]
}
PROMPT;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // HELPERS
    // ═════════════════════════════════════════════════════════════════════════

    // ─────────────────────────────────────────────────────────────────────────
    // POST CREATION
    // ─────────────────────────────────────────────────────────────────────────

    private function createPost(RawArticle $raw, Article $article, string $title, string $slug, array $parsed): void
    {
        try {
            // Cache admin/domain — không đổi trong runtime, không cần query lại mỗi bài
            $admin  = Cache::remember('default_admin',  3600, fn() => Admin::first());
            $domain = Cache::remember('default_domain', 3600, fn() => Domain::first());
            $kw     = $raw->keyword;

            if (!$admin || !$domain) {
                Log::warning('[WriteArticle] Skipping Post creation: no admin or domain found');
                return;
            }

            $categoryId = $kw->category_id ?? null;

            // Đảm bảo slug unique trong posts table
            $postSlug = $slug;
            $counter  = 1;
            while (Post::where('slug', $postSlug)->where('domain_id', $domain->id)->exists()) {
                $postSlug = $slug . '-' . $counter++;
            }

            Post::create([
                'id'          => Str::uuid(),
                'title'       => $title,
                'content'     => $parsed['content'] ?? '',
                'slug'        => $postSlug,
                'thumbnail'   => $raw->thumbnail,
                'category_id' => $categoryId,
                'author_id'   => $admin->id,
                'domain_id'   => $domain->id,
            ]);

            Log::info("[WriteArticle] Post created: {$title}");
        } catch (\Throwable $e) {
            // Không throw — Post creation failure không làm fail toàn bộ pipeline
            Log::warning("[WriteArticle] Post creation skipped: {$e->getMessage()}");
        }
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

    private function normalizeFaq(mixed $faq): array
    {
        if (!is_array($faq)) return [];
        return array_values(array_filter(
            $faq,
            fn($item) => isset($item['question'], $item['answer'])
                && !empty(trim($item['question']))
                && !empty(trim($item['answer']))
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

    public function failed(\Throwable $e): void
    {
        // Update raw article status nếu job permanently failed (hết retries)
        try {
            $this->rawArticle->update(['status' => 'failed']);
        } catch (\Throwable) {}

        Log::error('[WriteArticle] Permanently failed: ' . $e->getMessage());
    }
}
