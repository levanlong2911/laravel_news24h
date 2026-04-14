<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

// RedditService chưa được implement — controller này tạm disabled
class RedditController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(['message' => 'Reddit integration not implemented yet.'], 501);
    }

    public function subreddit(Request $request)
    {
        return response()->json(['message' => 'Reddit integration not implemented yet.'], 501);
    }
}
