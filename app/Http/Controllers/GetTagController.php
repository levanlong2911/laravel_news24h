<?php

namespace App\Http\Controllers;

use App\Services\Admin\TagService;
use Illuminate\Http\Request;

class GetTagController extends Controller
{
    private TagService $tagService;

    public function __construct
    (
        TagService $tagService
    )
    {
        $this->tagService = $tagService;
    }

    public function getTags(Request $request)
    {
        $categoryId = $request->category_id;
        $tags = $this->tagService->getListTagByCategoryId($categoryId);
        return response()->json($tags);
    }
}
