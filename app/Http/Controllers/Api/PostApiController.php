<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PostApiController extends Controller
{
    /**
     * LIST POSTS (BY DOMAIN)
     */
    public function index(Request $request)
    {
        $domain = currentDomain();

        if (!$domain) {
            return ApiResponse::error('Domain not found', 404);
        }

        $page = max((int) $request->get('page', 1), 1);

        $cacheKey = "public_posts:{$domain->id}:page:{$page}";

        try {

            if (Cache::getDefaultDriver() === 'redis') {

                // ✅ PROD – Redis (support tags)
                $posts = Cache::tags([
                    "domain:{$domain->id}",
                    "posts"
                ])->remember(
                    $cacheKey,
                    now()->addMinutes(5),
                    fn () => $this->queryPosts($domain)
                );

            } else {

                // ✅ LOCAL – File / Database cache (NO TAGS)
                $posts = Cache::remember(
                    $cacheKey,
                    now()->addMinutes(5),
                    fn () => $this->queryPosts($domain)
                );
            }

            return ApiResponse::success($posts);

        } catch (\Throwable $e) {

            Log::error('POST API INDEX ERROR', [
                'domain_id' => $domain->id,
                'message'   => $e->getMessage(),
            ]);

            return ApiResponse::error(
                'Service temporarily unavailable',
                503
            );
        }
    }

    protected function queryPosts($domain)
    {
        return Post::query()
            ->select([
                'id',
                'title',
                'slug',
                'thumbnail',
                'category_id',
                'created_at',
            ])
            ->with('category:id,name')
            ->where('domain_id', $domain->id)
            ->where('is_active', true)
            ->orderByDesc('created_at')
            ->paginate(20);
    }

    /**
     * POST DETAIL (SLUG + DOMAIN)
     */
    public function show(Request $request, string $slug)
    {
        $domain = currentDomain();

        if (!$domain) {
            return ApiResponse::error('Domain not found', 404);
        }

        $cacheKey = "public_post_detail:{$domain->id}:{$slug}";
        $isRedis  = Cache::getDefaultDriver() === 'redis';

        try {

            $data = $isRedis
                ? Cache::tags([
                    "domain:{$domain->id}",
                    "post:{$slug}",
                ])->remember(
                    $cacheKey,
                    now()->addMinutes(15),
                    fn () => $this->getPostDetailData($domain, $slug, true)
                )
                : Cache::remember(
                    $cacheKey,
                    now()->addMinutes(15),
                    fn () => $this->getPostDetailData($domain, $slug, false)
                );

            return ApiResponse::success($data);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {

            return ApiResponse::error('Post not found', 404);

        } catch (\Throwable $e) {

            report($e);

            return ApiResponse::error(
                'Service temporarily unavailable',
                503
            );
        }
    }

    protected function getPostDetailData($domain, string $slug, bool $useTag = false): array
    {
        $post = Post::query()
            ->with([
                'admin:id,name',
                'category:id,name',
            ])
            ->where('domain_id', $domain->id)
            ->where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        $relatedCacheKey = "related_posts:{$domain->id}:{$post->category_id}";

        if ($useTag) {

            $relatedPosts = Cache::tags([
                "domain:{$domain->id}",
                "category:{$post->category_id}",
            ])->remember(
                $relatedCacheKey,
                now()->addMinutes(30),
                fn () => $this->queryRelatedPosts($post, $domain)
            );

        } else {

            // ⚠️ LOCAL / FILE CACHE → KHÔNG DÙNG TAG
            $relatedPosts = Cache::remember(
                $relatedCacheKey,
                now()->addMinutes(30),
                fn () => $this->queryRelatedPosts($post, $domain)
            );
        }

        return [
            'post'          => $post,
            'related_posts' => $relatedPosts,
        ];
    }

    protected function queryRelatedPosts($post, $domain)
    {
        return Post::query()
            ->select([
                'id',
                'title',
                'slug',
                'thumbnail',
                'created_at',
            ])
            ->where('domain_id', $domain->id)
            ->where('category_id', $post->category_id)
            ->where('is_active', true)
            ->where('id', '!=', $post->id)
            ->orderByDesc('created_at')
            ->limit(6)
            ->get();
    }

}
