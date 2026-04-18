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
