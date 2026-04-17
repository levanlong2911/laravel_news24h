<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessKeywordJob;
use App\Models\Admin;
use App\Models\Article;
use App\Models\Domain;
use App\Models\Keyword;
use App\Models\Post;
use App\Services\Admin\ClaudeWriterService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class ArticleController extends Controller
{
    public function index(Request $request)
    {
        $status    = $request->get('status', 'all');
        $keywordId = $request->get('keyword_id');

        $articles = Article::with(['keyword', 'crawler'])
            ->when($status !== 'all', fn($q) => $q->where('status', $status))
            ->when($keywordId,        fn($q) => $q->where('keyword_id', $keywordId))
            ->orderByDesc('viral_score')
            ->orderByDesc('created_at')
            ->paginate(20);

        $keywords = Keyword::where('is_active', true)->orderBy('sort_order')->get();
        return view('admin.articles.index', [
            'route'     => 'article',
            'action'    => 'article-index',
            'menu'      => 'menu-open',
            'active'    => 'active',
            'articles'  => $articles,
            'keywords'  => $keywords,
            'status'    => $status,
            'keywordId' => $keywordId,
        ]);
    }

    public function show(Article $article)
    {
        return view('admin.articles.show', [
            'route'   => 'article',
            'action'  => 'article-show',
            'menu'    => 'menu-open',
            'active'  => 'active',
            'article' => $article,
        ]);
    }

    public function publish(Article $article)
    {
        $article->update(['status' => 'published', 'published_at' => now()]);
        return back()->with('success', "Published: {$article->title}");
    }

    public function unpublish(Article $article)
    {
        $article->update(['status' => 'pending', 'published_at' => null]);
        return back()->with('success', 'Unpublished');
    }

    public function destroy(Article $article)
    {
        $article->delete();
        return redirect()->route('article.index')->with('success', 'Deleted');
    }

    public function destroySelected(Request $request)
    {
        $ids = array_filter((array) $request->get('selected_ids', []));
        if (empty($ids)) {
            return redirect()->route('article.index')->with('error', 'Chua chon bai nao.');
        }

        $count = Article::whereIn('id', $ids)->delete();

        return redirect()->route('article.index', array_filter([
            'status'     => $request->status,
            'keyword_id' => $request->keyword_id,
        ]))->with('success', "Da xoa {$count} bai.");
    }

    public function destroyAll(Request $request)
    {
        $count = Article::query()
            ->when($request->status && $request->status !== 'all', fn($q) => $q->where('status', $request->status))
            ->when($request->keyword_id, fn($q) => $q->where('keyword_id', $request->keyword_id))
            ->delete();

        return redirect()->route('article.index', array_filter([
            'status'     => $request->status,
            'keyword_id' => $request->keyword_id,
        ]))->with('success', "Deleted {$count} articles.");
    }

    public function publishAll(Request $request)
    {
        $count = Article::where('status', 'pending')
            ->when($request->keyword_id, fn($q) => $q->where('keyword_id', $request->keyword_id))
            ->update(['status' => 'published', 'published_at' => now()]);

        return back()->with('success', "Published {$count} articles");
    }

    public function generateAll()
    {
        $keywords = Keyword::where('is_active', true)->get();
        foreach ($keywords as $keyword) {
            ProcessKeywordJob::dispatch($keyword)->onQueue('articles');
        }
        return back()->with('success', "Dispatched {$keywords->count()} keywords to queue");
    }

    public function generateOne(Request $request)
    {
        $keyword = Keyword::find($request->get('keyword_id'));
        if (!$keyword) {
            return back()->with('error', 'Keyword not found');
        }
        ProcessKeywordJob::dispatch($keyword)->onQueue('articles');
        return back()->with('success', "Dispatched: {$keyword->name}");
    }

    public function clearCache()
    {
        Cache::flush();
        return back()->with('success', 'Cache cleared');
    }

    // Article → Claude (trực tiếp, không queue) → Post
    public function sendToClaude(Request $request, ClaudeWriterService $claude)
    {
        set_time_limit(300);

        $ids = array_filter((array) $request->get('selected_ids', []));
        if (empty($ids)) {
            return back()->with('error', 'Chua chon bai viet nao.');
        }

        if (count($ids) > 5) {
            return back()->with('error', 'Xu ly truc tiep toi da 5 bai moi lan.');
        }

        $key = 'article-send-claude';
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            return back()->with('error', "Qua nhieu request. Doi {$seconds}s.");
        }
        RateLimiter::hit($key, 60);

        // Dùng user đang đăng nhập làm author — bài sẽ hiện đúng trong list của họ
        $admin  = auth()->user();
        $domain = Cache::remember('default_domain', 3600, fn() => Domain::first());

        if (!$admin || !$domain) {
            return back()->with('error', 'Khong tim thay admin hoac domain.');
        }

        $articles = Article::with('keyword')->whereIn('id', $ids)->get();
        $done     = 0;
        $errors   = [];

        foreach ($articles as $article) {
            if ($article->status === 'processing') {
                $errors[] = "'{$article->title}' dang xu ly.";
                continue;
            }

            $article->update(['status' => 'processing']);

            try {
                $rawText = $this->cleanForPrompt($article->content ?? '');
                $kwName  = $article->keyword->name ?? '';

                if (strlen($rawText) < 100) {
                    throw new \RuntimeException('Content qua ngan de xu ly');
                }

                // Buoc 1: Haiku extract facts
                $facts = $claude->generate(
                    $this->haikuPrompt($kwName, $rawText),
                    'haiku'
                );

                if (empty(trim($facts))) {
                    throw new \RuntimeException('Claude Haiku tra ve trong');
                }

                // Buoc 2: Sonnet viet bai → JSON
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
                        'fb_image_text'    => null,
                        'fb_quote'         => null,
                        'fb_post_content'  => null,
                    ];
                }

                // Buoc 3: Luu vao Post
                $finalTitle = trim($parsed['title'] ?? $article->title);
                $slug       = $this->uniqueSlug(Str::slug($finalTitle ?: 'article'));

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
                    'fb_image_text'    => $parsed['fb_image_text']   ?? null,
                    'fb_quote'         => ($parsed['fb_quote'] ?? '') ?: null,
                    'fb_post_content'  => $parsed['fb_post_content'] ?? null,
                ]);

                $article->update(['status' => 'published', 'published_at' => now()]);
                $done++;

                Log::info("[sendToClaude] OK: {$finalTitle}");

            } catch (\Throwable $e) {
                $article->update(['status' => 'failed']);
                $errors[] = "'{$article->title}': {$e->getMessage()}";
                Log::error("[sendToClaude] Failed [{$article->id}]: {$e->getMessage()}");
            }
        }

        $msg = "Hoan thanh {$done}/" . count($articles) . " bai.";
        if ($errors) {
            return back()->with('error', $msg . ' Loi: ' . implode('; ', $errors));
        }

        return back()->with('success', $msg);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

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

            // Lọc URL ảnh còn sót (CDN links, direct image URLs)
            if (preg_match('/^https?:\/\/\S+\.(jpg|jpeg|png|gif|webp|svg|avif)(\?[^\s]*)?$/i', $t)) continue;

            // Lọc photo credit / caption ảnh
            if (preg_match('/\b(getty|ap photo|reuters|afp|shutterstock|wire image|photo by|image by|credit:|imagn)\b/i', $t)) continue;

            // Lọc copyright lines
            if (preg_match('/^©|\bcopyright\b|\ball rights reserved\b/i', $t)) continue;

            // Lọc dòng chỉ toàn URL
            if (preg_match('/^https?:\/\/\S+$/', $t)) continue;

            $result[] = $t;
        }

        return implode("\n\n", $result);
    }

    private function haikuPrompt(string $keyword, string $content): string
    {
        return <<<PROMPT
You are an expert news fact extractor. Extract all newsworthy information from the article below.

Topic: {$keyword}

EXTRACT:
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

TITLE: 60-70 characters. Factually accurate. Includes main keyword.
META DESCRIPTION: 150-160 characters. Lead with emotional hook or surprising fact.
CONTENT: 3,000-4,000 characters. HTML format (<p>, <h2> tags). 2 sentences per paragraph.

FB_IMAGE_TEXT: 1-2 short sentences (80-150 chars) to overlay on a cover image in Canva.
  Write as natural flowing sentences — no "BREAKING:" prefix, no format labels.
  Use the most compelling fact or narrative hook from the article. Must read well in isolation.

FB_QUOTE: The single most quotable direct quote from a named person in the article.
  Include attribution naturally (e.g. "I'm here to win" — John Smith).
  Return empty string "" if the article has no strong direct quote worth using.

FB_POST_CONTENT: A Facebook caption ready to paste (no URL — link goes in first comment).
  Facebook shows ~200 chars before "See More" on mobile — first 2 lines MUST hook standalone.
  Structure (use literal line breaks \n):
    Line 1: Strongest hook — 1 sentence, ≤90 chars, triggers emotion or curiosity
    Line 2: Amplify — 1 sentence, ≤110 chars, deepens the intrigue
    [blank line]
    Lines 3-5: Key details from the article (visible after tapping See More)
    [blank line]
    Last line: Natural CTA — ask a question, invite a tag, or prompt a reaction.
              Must match the content tone. Never generic ("read more", "click link").
  Use emojis sparingly. Write in the same language as the article content. 250-450 chars total.

OUTPUT — Return ONLY this JSON (no markdown, no code block):
{
  "title": "...",
  "meta_description": "...",
  "content": "...",
  "fb_image_text": "...",
  "fb_quote": "...",
  "fb_post_content": "..."
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
}
