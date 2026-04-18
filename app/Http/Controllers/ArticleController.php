<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessKeywordJob;
use App\Models\Article;
use App\Models\Domain;
use App\Models\Keyword;
use App\Models\Post;
use App\Services\Admin\ArticlePipelineService;
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

    public function storeManual(Request $request)
    {
        $request->validate([
            'source_url' => 'required|url|max:2000',
            'title'      => 'required|string|max:500',
            'content'    => 'required|string',
            'keyword_id' => 'nullable|exists:keywords,id',
        ]);

        $urlHash = md5($request->source_url);

        if (Article::where('source_url_hash', $urlHash)->exists()) {
            return back()->with('error', 'URL này đã được crawl rồi.');
        }

        $keyword = $request->keyword_id ? Keyword::find($request->keyword_id) : null;
        $slug    = $this->uniqueSlug(Str::slug($request->title ?: 'article'));
        $domain  = str_replace('www.', '', parse_url($request->source_url, PHP_URL_HOST) ?? '');

        Article::create([
            'keyword_id'      => $request->keyword_id,
            'category_id'     => $keyword?->category_id,
            'source_url'      => $request->source_url,
            'source_url_hash' => $urlHash,
            'source_title'    => $request->title,
            'source_name'     => $domain,
            'thumbnail'       => $request->thumbnail ?: null,
            'title'           => $request->title,
            'slug'            => $slug,
            'content'         => $request->content,
            'viral_score'     => 0,
            'status'          => 'pending',
            'expires_at'      => now()->addHours(48),
            'crawled_by'      => auth()->id(),
        ]);

        return back()->with('success', 'Đã lưu: ' . Str::limit($request->title, 80));
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

    // Article → ArticlePipelineService → Post
    public function sendToClaude(Request $request, ArticlePipelineService $pipeline)
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
                $result = $pipeline->run(
                    rawHtml:    $article->content ?? '',
                    keyword:    $article->keyword->name ?? '',
                    categoryId: $article->keyword->category_id ?? '',
                );

                $parsed     = $result->parsed;
                $finalTitle = $result->title() ?: $article->title;
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

                Log::info("[sendToClaude] OK: {$finalTitle}", [
                    'hook_type'  => $result->hookResult->detectedType,
                    'hook_score' => $result->hookResult->bestScore,
                    'context_id' => $result->context?->id,
                ]);

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

    public function synthesize(Request $request, ArticlePipelineService $pipeline)
    {
        set_time_limit(300);

        $ids = array_filter((array) $request->get('selected_ids', []));

        if (count($ids) < 2) {
            return back()->with('error', 'Cần chọn ít nhất 2 bài để tổng hợp.');
        }
        if (count($ids) > 5) {
            return back()->with('error', 'Tối đa 5 bài mỗi lần tổng hợp.');
        }

        $key = 'article-send-claude';
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->with('error', 'Quá nhiều request. Đợi ' . RateLimiter::availableIn($key) . 's.');
        }
        RateLimiter::hit($key, 60);

        $articles = Article::with('keyword')->whereIn('id', $ids)->get();

        // Validate cùng category
        $categoryIds = $articles->pluck('keyword.category_id')->unique()->filter();
        if ($categoryIds->count() > 1) {
            $kwNames = $articles->pluck('keyword.name')->unique()->implode(', ');
            return back()->with('error', "Bài viết thuộc nhiều category ({$kwNames}). Chỉ chọn bài cùng keyword.");
        }

        $admin  = auth()->user();
        $domain = Cache::remember('default_domain', 3600, fn() => Domain::first());

        if (!$admin || !$domain) {
            return back()->with('error', 'Không tìm thấy admin hoặc domain.');
        }

        // Primary = bài có viral_score cao nhất → dùng thumbnail + keyword
        $primary = $articles->sortByDesc('viral_score')->first();

        // Concat content, mỗi source có label riêng
        $concatContent = $articles->map(
            fn($a) => "[SOURCE — {$a->source_name}]\n{$a->content}"
        )->implode("\n\n---\n\n");

        try {
            $articles->each(fn($a) => $a->update(['status' => 'processing']));

            $result     = $pipeline->run(
                rawHtml:    $concatContent,
                keyword:    $primary->keyword->name ?? '',
                categoryId: $primary->keyword->category_id ?? '',
            );
            $parsed     = $result->parsed;
            $finalTitle = $result->title() ?: $primary->title;
            $slug       = $this->uniqueSlug(Str::slug($finalTitle ?: 'article'));

            Post::create([
                'id'               => Str::uuid(),
                'title'            => $finalTitle,
                'meta_description' => Str::limit($parsed['meta_description'] ?? '', 255),
                'content'          => $parsed['content'] ?? '',
                'slug'             => $slug,
                'thumbnail'        => $primary->thumbnail,
                'category_id'      => $primary->keyword->category_id ?? null,
                'author_id'        => $admin->id,
                'domain_id'        => $domain->id,
                'fb_image_text'    => $parsed['fb_image_text']   ?? null,
                'fb_quote'         => ($parsed['fb_quote'] ?? '') ?: null,
                'fb_post_content'  => $parsed['fb_post_content'] ?? null,
            ]);

            $sourceUrls = $articles->pluck('source_url')->filter()->values()->toArray();
            $articles->each(fn($a) => $a->update([
                'status'       => 'published',
                'published_at' => now(),
                'source_urls'  => $sourceUrls,
            ]));

            Log::info('[synthesize] OK: ' . $finalTitle, [
                'count'      => count($ids),
                'hook_type'  => $result->hookResult->detectedType,
                'hook_score' => $result->hookResult->bestScore,
            ]);

            return back()->with('success', 'Đã tổng hợp ' . count($ids) . ' bài → "' . $finalTitle . '"');

        } catch (\Throwable $e) {
            $articles->each(fn($a) => $a->update(['status' => 'failed']));
            Log::error('[synthesize] Failed: ' . $e->getMessage());
            return back()->with('error', 'Lỗi tổng hợp: ' . $e->getMessage());
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

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
