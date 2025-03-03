<?php

namespace App\Http\Controllers;

use App\Form\AdminCustomValidator;
use App\Services\Admin\CategoryService;
use App\Services\Admin\InforDomainService;
use App\Services\Admin\PostService;
use Illuminate\Http\Request;
use DOMDocument;
use DOMXPath;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;

class PostController extends Controller
{

    private CategoryService $categoryService;
    private InforDomainService $domainService;
    private AdminCustomValidator $form;
    private PostService $postService;

    public function __construct
    (
        CategoryService $categoryService,
        InforDomainService $domainService,
        AdminCustomValidator $form,
        PostService $postService
    )
    {
        $this->categoryService = $categoryService;
        $this->domainService = $domainService;
        $this->form = $form;
        $this->postService = $postService;
    }


    public function index()
    {
        // $listsDomain = $this->domainService->getListDomain();
        // $result = $this->domainService->getListDomainIds()->pluck('id');
        // dd($result);
        return view("post.index", [
            "route" => "post",
            "action" => "admin-post",
            "menu" => "menu-open",
            "active" => "active",
            // 'listsDomain' => $listsDomain,
            // "domainIds" => $this->domainService->getListDomainIds()->pluck('id'),
        ]);
    }

    public function add(Request $request)
    {
        // dd(11);
        $listsCate = $this->categoryService->getListCategory();
        if($request->isMethod('post')) {
            $this->form->validate($request, 'PostAddForm');
            dd($request->all());
            $addPost = $this->postService->create($request);
            if ($addPost) {
                return redirect()->route('admin.index')->with('success', __('messages.add_success'));
            }
            return redirect()->route('admin.index')->with('error', __('messages.add_error'));
        }
        return view("post.add", [
            "route" => "post",
            "action" => "post-index",
            "menu" => "menu-open",
            "active" => "active",
            "listsCate" => $listsCate,
        ]);
    }
}
