<?php

namespace App\Http\Controllers;

use App\Models\Post;
use Illuminate\Http\Request;

class SitemapController extends Controller
{
    public function index()
    {
        $urls = [];
        // Lấy bài viết theo từng chunk nhỏ
        Post::select('slug', 'updated_at')->chunk(100, function ($posts) use (&$urls) {
            foreach ($posts as $post) {
                $urls[] = [
                    'url' => url('/post/' . $post->slug),
                    'lastmod' => $post->updated_at->toDateString(),
                ];
            }
        });

        $xml = view('sitemap', ['urls' => $urls])->render();

        return response($xml, 200)->header('Content-Type', 'application/xml');
    }
}
