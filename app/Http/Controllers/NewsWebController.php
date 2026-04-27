<?php

namespace App\Http\Controllers;

use App\Form\WebUpdateValidate;
use App\Repositories\Interfaces\NewsWebRepositoryInterface;
use App\Services\Admin\CategoryService;
use App\Services\Admin\NewsWebService;
use Illuminate\Http\Request;

class NewsWebController extends Controller
{
    private NewsWebRepositoryInterface $newsWebRepository;
    private NewsWebService $newsWebService;
    private CategoryService $categoryService;
    private WebUpdateValidate $webUpdateValidate;

    public function __construct
    (
        NewsWebRepositoryInterface $newsWebRepository,
        NewsWebService $newsWebService,
        CategoryService $categoryService,
        WebUpdateValidate $webUpdateValidate,
    )
    {
        $this->newsWebRepository = $newsWebRepository;
        $this->newsWebService = $newsWebService;
        $this->categoryService = $categoryService;
        $this->webUpdateValidate = $webUpdateValidate;
    }

    public function index(Request $request)
    {
        $listNewsWeb = $this->newsWebService->getListNewsWeb($request);
        $categories  = $this->categoryService->getAllCategories();
        return view("newsweb.index", [
            "route"       => "news-web",
            "action"      => "admin-news-web",
            "menu"        => "menu-open",
            "active"      => "active",
            'listNewsWeb' => $listNewsWeb,
            'categories'  => $categories,
            "webIds"      => $listNewsWeb->pluck('id'),
        ]);
    }

    public function add(Request $request)
    {
        $listsCate = $this->categoryService->getAllCategories();
        if ($request->isMethod('post')) {
            try {
                $this->newsWebService->addNewsWeb($request);
                return redirect()->route('news-web.index')->with("success", __('messages.add_success'));
            } catch (\RuntimeException $e) {
                return back()->with("error", $e->getMessage());
            } catch (\Throwable $e) {
                return back()->with("error", __('messages.add_error'));
            }
        }
        return view("newsweb.add", [
            "route" => "news-web",
            "action" => "newsweb-index",
            "menu" => "menu-open",
            "active" => "active",
            "listsCate" => $listsCate,
        ]);
    }
    public function detail($id)
    {
        $infoWeb = $this->newsWebService->getByIdWeb($id);
        if(!$infoWeb) {
            return redirect()->back()->with('error', __('messages.account_not_found'));
        }
        return view("newsweb.detail", [
            "route" => "news-web",
            "action" => "newsweb-detail",
            "menu" => "menu-open",
            "active" => "active",
            "infoWeb" => $infoWeb,
        ]);

    }

    public function update(Request $request, $id)
    {
        $listsCate = $this->categoryService->getAllCategories();
        $infoWeb = $this->newsWebService->getByIdWeb($id);

        if(is_null($infoWeb)) {
            return redirect()->back()->with('error', __('messages.account_not_found'));
        }
        if ($request->isMethod('post')) {
            $this->webUpdateValidate->validate($request, $id);
            $updateTag = $this->newsWebService->updateWeb($id, $request);
            if ($updateTag) {
                return redirect()->route('news-web.index')->with("success", __('messages.add_success'));
            }
            return back()->with("error", __('messages.add_error'));
        }
        return view("newsweb.update", [
            "route" => "news-web",
            "action" => "newsweb-update",
            "menu" => "menu-open",
            "active" => "active",
            "listsCate" => $listsCate,
            "infoWeb" => $infoWeb,
        ]);
    }

    public function delete(Request $request)
    {
        $result = $this->newsWebService->deleteWebByIds($request);
        if ($result) {
            return redirect()->route('news-web.index')->with('success', __('messages.delete_success'));
        }
        return redirect()->back()->with('error', __('messages.delete_error'));
    }

}
