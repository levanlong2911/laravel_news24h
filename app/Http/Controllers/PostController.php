<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class PostController extends Controller
{
    public function index()
    {
        dd(11);
    }

    public function add()
    {
        return view("post.add", [
            "route" => "post",
            "action" => "post-index",
            "menu" => "menu-open",
            "active" => "active",
        ]);
    }
}
