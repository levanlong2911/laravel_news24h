<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

class PostApiController extends Controller
{
    // public function index()
    // {
    //     // Lấy danh sách bài viết
    //     $posts = Post::with(['admin', 'category'])->latest()->paginate(20);
    //     return response()->json($posts);

    // }
    public function index(Request $request)
    {
        $domain = $this->resolveDomain($request);
        // dd($domain);

        $page   = $request->get('page', 1);
        // dd($page);
        $cacheKey = "public_posts:{$domain}:page:{$page}";

        $posts = Cache::remember(
            $cacheKey,
            now()->addMinutes(5),
            function () use ($domain) {
                return Post::query()
                    ->select([
                        'id',
                        'title',
                        'slug',
                        'thumbnail',
                        'category_id',
                        'created_at'
                    ])
                    ->with([
                        'category:id,name'
                    ])
                    ->where('domain', $domain)
                    ->orderByDesc('created_at')
                    ->paginate(20);
            }
        );

        return response()->json($posts);
    }

    // public function show($slug)
    // {
    //     // Lấy bài viết chính và 6 bài cùng category bằng relationship
    //     $post = Post::with(['admin', 'category', 'category.posts' => function($query) {
    //         $query->latest()->take(7);
    //     }])
    //     ->where('slug', $slug)
    //     ->firstOrFail();

    //     // Lọc bài chính ra khỏi related posts
    //     $relatedPosts = $post->category->posts->where('id', '!=', $post->id)->values();
    //     return response()->json([
    //         'post' => $post,
    //         'related_posts' => $relatedPosts
    //     ]);
    // }
    public function show(Request $request, string $slug)
    {
        $domain = $this->resolveDomain($request);
        $cacheKey = "public_post_detail:{$domain}:{$slug}";

        $data = Cache::remember(
            $cacheKey,
            now()->addMinutes(15),
            function () use ($domain, $slug) {

                $post = Post::query()
                    ->with([
                        'admin:id,name',
                        'category:id,name'
                    ])
                    ->where('slug', $slug)
                    ->where('domain', $domain)
                    ->firstOrFail();

                $relatedPosts = Cache::remember(
                    "related_posts:{$domain}:{$post->category_id}",
                    now()->addMinutes(30),
                    function () use ($post, $domain) {
                        return Post::query()
                            ->select('id', 'title', 'slug', 'thumbnail', 'created_at')
                            ->where('category_id', $post->category_id)
                            ->where('domain', $domain)
                            ->where('is_active', true)
                            ->where('id', '!=', $post->id)
                            ->inRandomOrder()
                            ->limit(6)
                            ->get();
                    }
                );

                return [
                    'post' => $post,
                    'related_posts' => $relatedPosts
                ];
            }
        );

        return response()->json($data);
    }

    /**
     * DOMAIN SAFE RESOLVER
     */
    protected function resolveDomain(Request $request): string
    {
        $host = $request->getHost();
        // dd($host);

        $allowed = [
            'lifennew.com',
            'newsday.feji.io',
            '127.0.0.1',
        ];

        abort_unless(in_array($host, $allowed), 404);

        return $host;
    }
}
