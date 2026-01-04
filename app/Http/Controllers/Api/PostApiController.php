<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Support\CacheVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Cache\TaggableStore;

class PostApiController extends Controller
{
    public function index(Request $request)
    {
        $domain = $request->get('domain');

        $page    = max((int) $request->query('page', 1), 1);
        $perPage = 20;

        $cacheKey = sprintf(
            'public_posts:%s:%s:page:%d',
            CacheVersion::POSTS,
            $domain->id,
            $page
        );

        $useTag = Cache::getStore() instanceof TaggableStore;

        try {
            $posts = $useTag
                ? Cache::tags(["domain:$domain->id", 'posts'])
                    ->remember($cacheKey, 300, fn () =>
                        $this->queryPosts($domain, $perPage, $page)
                    )
                : Cache::remember($cacheKey, 300, fn () =>
                        $this->queryPosts($domain, $perPage, $page)
                  );

            return response()
                ->json(['success' => true, 'data' => $posts])
                ->withHeaders($this->cacheHeaders(60));

        } catch (\Throwable $e) {

            Log::critical('POST INDEX FATAL', [
                'domain_id' => $domain->id,
                'error'     => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Service unavailable',
            ], 503);
        }
    }

    protected function queryPosts($domain, int $perPage, int $page)
    {
        return Post::query()
            ->select(['id','title', 'content', 'slug','thumbnail','category_id','updated_at'])
            ->with('category:id,name')
            ->where('domain_id', $domain->id)
            ->where('is_active', true)
            ->latest()
            ->paginate($perPage, ['*'], 'page', $page);
    }

    public function show(Request $request, string $slug)
    {
        $domain = $request->get('domain');

        $cacheKey = sprintf(
            'public_post:%s:%s:%s',
            CacheVersion::POST,
            $domain->id,
            $slug
        );

        $useTag = Cache::getStore() instanceof TaggableStore;

        try {
            $data = $useTag
                ? Cache::tags(["domain:$domain->id", "post:$slug"])
                    ->remember($cacheKey, 900, fn () =>
                        $this->getPostDetailData($domain, $slug, true)
                    )
                : Cache::remember($cacheKey, 900, fn () =>
                        $this->getPostDetailData($domain, $slug, false)
                  );

            return response()
                ->json(['success' => true, 'data' => $data])
                ->withHeaders($this->cacheHeaders(300));

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {

            return response()->json([
                'success' => false,
                'message' => 'Post not found',
            ], 404);

        } catch (\Throwable $e) {

            Log::error('POST DETAIL ERROR', [
                'domain_id' => $domain->id,
                'slug'      => $slug,
                'error'     => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Service unavailable',
            ], 503);
        }
    }

    // protected function success($data)
    // {
    //     return response()->json([
    //         'success' => true,
    //         'data'    => $data,
    //     ]);
    // }

    // protected function error(string $message, int $status)
    // {
    //     return response()->json([
    //         'success' => false,
    //         'message' => $message,
    //     ], $status);
    // }

    protected function getPostDetailData($domain, string $slug, bool $useTag): array
    {
        $post = Post::query()
            ->with(['admin:id,name', 'category:id,name'])
            ->where('domain_id', $domain->id)
            ->where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        $relatedKey = "related_posts:$domain->id:$post->category_id";

        $related = $useTag
            ? Cache::tags(["domain:$domain->id", "category:$post->category_id"])
                ->remember($relatedKey, 1800, fn () =>
                    $this->queryRelatedPosts($post, $domain)
                )
            : Cache::remember($relatedKey, 1800, fn () =>
                    $this->queryRelatedPosts($post, $domain)
              );

        return [
            'post'          => $post,
            'related_posts' => $related,
        ];
    }

    protected function queryRelatedPosts($post, $domain)
    {
        return Post::query()
            ->select(['id','title','slug','thumbnail','created_at'])
            ->where('domain_id', $domain->id)
            ->where('category_id', $post->category_id)
            ->where('id', '!=', $post->id)
            ->where('is_active', true)
            ->latest()
            ->limit(6)
            ->get();
    }

    protected function cacheHeaders(int $seconds): array
    {
        return [
            'Cache-Control' => "public, s-maxage=$seconds, max-age=$seconds",
            'Vary'          => 'Accept-Encoding, Host',
        ];
    }



    // public function index(Request $request)
    // {
    //     $domain = $request->get('domain');

    //     if (!$domain) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Domain not found',
    //         ], 404);
    //     }

    //     $page    = max((int) $request->query('page', 1), 1);
    //     $perPage = 20;

    //     $cacheKey = "posts:{$domain->id}:page:{$page}";
    //     $useTag   = Cache::getStore() instanceof TaggableStore;

    //     $posts = $useTag
    //         ? Cache::tags([
    //             "domain:{$domain->id}",
    //             "posts",
    //         ])->remember(
    //             $cacheKey,
    //             now()->addMinutes(5),
    //             fn () => $this->queryPosts($domain, $perPage, $page)
    //         )
    //         : Cache::remember(
    //             $cacheKey,
    //             now()->addMinutes(5),
    //             fn () => $this->queryPosts($domain, $perPage, $page)
    //         );

    //     return response()->json([
    //         'success' => true,
    //         'data' => $posts,
    //     ]);
    // }

    // protected function queryPosts($domain, int $perPage, int $page)
    // {
    //     return Post::query()
    //         ->where('domain_id', $domain->id)
    //         ->where('is_active', 1)
    //         ->orderByDesc('created_at')
    //         ->paginate(20);
    //         // ->paginate(
    //         //     $perPage,
    //         //     ['*'],
    //         //     'page',
    //         //     $page
    //         // );
    // }

    // /**
    //  * POST DETAIL (SLUG + DOMAIN)
    //  */
    // public function show(Request $request, string $slug)
    // {
    //     $domain = $request->get('domain');

    //     if (!$domain) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Domain not found',
    //         ], 404);
    //     }

    //     $cacheKey = "post:{$domain->id}:{$slug}";
    //     $useTag   = Cache::getStore() instanceof TaggableStore;

    //     $post = $useTag
    //         ? Cache::tags([
    //             "domain:{$domain->id}",
    //             "post:{$slug}",
    //         ])->remember(
    //             $cacheKey,
    //             now()->addMinutes(15),
    //             fn () => $this->queryPostDetail($domain, $slug)
    //         )
    //         : Cache::remember(
    //             $cacheKey,
    //             now()->addMinutes(15),
    //             fn () => $this->queryPostDetail($domain, $slug)
    //         );

    //     return response()->json([
    //         'success' => true,
    //         'data' => $post,
    //     ]);
    // }

    // protected function queryPostDetail($domain, string $slug)
    // {
    //     return Post::query()
    //         ->where('domain_id', $domain->id)
    //         ->where('slug', $slug)
    //         ->where('is_active', 1)
    //         ->firstOrFail();
    // }

}
