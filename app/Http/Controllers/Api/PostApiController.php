<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;

class PostApiController extends Controller
{
    public function index()
    {
        // Lấy danh sách bài viết
        $posts = Post::with(['admin', 'category'])->latest()->paginate(20);
        return response()->json($posts);
    }

    public function show($slug)
    {
        // Lấy bài viết chính và 6 bài cùng category bằng relationship
        $post = Post::with(['admin', 'category', 'category.posts' => function($query) {
            $query->latest()->take(6);
        }])
        ->where('slug', $slug)
        ->firstOrFail();

        // Lọc bài chính ra khỏi related posts
        $relatedPosts = $post->category->posts->where('id', '!=', $post->id)->values();

        return response()->json([
            'post' => $post,
            'related_posts' => $relatedPosts
        ]);
    }
}
