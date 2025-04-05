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
        $posts = Post::with(['admin', 'category'])->latest()->paginate(10);
        return response()->json($posts);
    }

    public function show($id)
    {
        // Lấy chi tiết bài viết theo ID
        $post = Post::findOrFail($id);
        return response()->json($post);
    }
}
