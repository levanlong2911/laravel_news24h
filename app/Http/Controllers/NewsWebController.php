<?php

namespace App\Http\Controllers;

use App\Repositories\Interfaces\NewsWebRepositoryInterface;
use App\Services\Admin\CategoryService;
use App\Services\Admin\NewsWebService;
use Illuminate\Http\Request;

class NewsWebController extends Controller
{
    private NewsWebRepositoryInterface $newsWebRepository;
    private NewsWebService $newsWebService;
    private CategoryService $categoryService;

    public function __construct
    (
        NewsWebRepositoryInterface $newsWebRepository,
        NewsWebService $newsWebService,
        CategoryService $categoryService,
    )
    {
        $this->newsWebRepository = $newsWebRepository;
        $this->newsWebService = $newsWebService;
        $this->categoryService = $categoryService;
    }

    public function index()
    {
        $listNewsWeb = $this->newsWebService->getListNewsWeb();
        return view("newsweb.index", [
            "route" => "news-web",
            "action" => "admin-news-web",
            "menu" => "menu-open",
            "active" => "active",
            'listNewsWeb' => $listNewsWeb,
            "tagIds" => $this->newsWebService->getListNewsWebIds()->pluck('id'),
        ]);
    }

    public function add(Request $request)
    {
        $listsCate = $this->categoryService->getListCategory();
        if ($request->isMethod('post')) {
            $url = $this->newsWebService->addNewsWeb($request);
            if ($url) {
                return redirect()->route('newsweb.index')->with("success",__('messages.add_success'));
            }
            return back()->with("error",__('messages.add_error'));
        }
        return view("newsweb.add", [
            "route" => "tag",
            "action" => "tag-index",
            "menu" => "menu-open",
            "active" => "active",
            "listsCate" => $listsCate,
        ]);
    }
}
