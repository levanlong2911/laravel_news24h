<?php

namespace App\Http\Controllers;

use App\Repositories\Interfaces\CategoryRepositoryInterface;
use App\Services\Admin\CategoryService;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    private CategoryRepositoryInterface $categoryRepository;
    private CategoryService $categoryService;

    public function __construct
    (
        CategoryRepositoryInterface $categoryRepository,
        CategoryService $categoryService
    )
    {
        $this->categoryRepository = $categoryRepository;
        $this->categoryService = $categoryService;
    }

    public function index()
    {
        $listsCate = $this->categoryService->getListCategory();
        return view("category.index", [
            "route" => "category",
            "action" => "admin-category",
            "menu" => "menu-open",
            "active" => "active",
            'listsCate' => $listsCate,
            "listIdCate" => $this->categoryService->getListCategory()->pluck('id'),
        ]);
    }

    public function add(Request $request)
    {
        if ($request->isMethod('post')) {
            $params = [
                'name' => $request->name,
            ];
            $addCate = $this->categoryRepository->create($params);
            if ($addCate) {
                return redirect()->route('admin.category.index')->with('success', __('messages.add_success'));
            }
            return redirect()->route('admin.category.index')->with('error', __('messages.add_error'));
        }
        return view("category.add", [
            "route" => "category",
            "action" => "category-index",
            "menu" => "menu-open",
            "active" => "active",
        ]);
    }

    public function detail(Request $request)
    {
        $infoCate = $this->categoryService->getInfoCate($request->id);
        if(!$infoCate) {
            return redirect()->back()->with('error', __('messages.account_not_found'));
        }
        return view("category.detail", [
            "route" => "category",
            "action" => "category-detail",
            "menu" => "menu-open",
            "active" => "active",
            'infoCate' => $infoCate,
        ]);
    }

    public function update(Request $request)
    {
        $infoCate = $this->categoryService->getInfoCate($request->id);
        if ($request->isMethod('post')) {
            $params = [
                'name' => $request->name,
            ];
            $updateCate = $this->categoryRepository->update($request->id, $params);
            if ($updateCate) {
                return redirect()->route('admin.category.index')->with('success', __('messages.add_success'));
            }
            return back()->with("error",__('messages.add_error'));
        }
        return view("category.update", [
            "route" => "category",
            "action" => "category-update",
            "menu" => "menu-open",
            "active" => "active",
            "infoCate" => $infoCate,
        ]);
    }

    public function delete(Request $request)
    {
        $del = $this->categoryService->delete($request);
        if ($del) {
            return redirect()
                ->route('admin.category.index')
                ->with("success", __("messages.delete_success"));
        }
        return redirect()
        ->route('admin.category.index')
        ->with("error", __("messages.delete_error"));
    }
}
