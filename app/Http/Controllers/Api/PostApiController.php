<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use Illuminate\Http\Request;

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
        // Lấy chi tiết bài viết theo ID
        $post = Post::with(['admin', 'category'])->where('slug', $slug)->firstOrFail();
        return response()->json($post);
    }
}
