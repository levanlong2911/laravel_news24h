<?php

namespace App\Http\Controllers;

use App\Form\AdminCustomValidator;
use App\Repositories\Interfaces\TagRepositoryInterface;
use App\Services\Admin\CategoryService;
use App\Services\Admin\TagService;
use Illuminate\Http\Request;

class TagController extends Controller
{
    private TagRepositoryInterface $tagRepositoryInterface;
    private TagService $tagService;
    private CategoryService $categoryService;
    private AdminCustomValidator $form;

    public function __construct
    (
        TagRepositoryInterface $tagRepositoryInterface,
        TagService $tagService,
        CategoryService $categoryService,
        AdminCustomValidator $form
    )
    {
        $this->tagRepositoryInterface = $tagRepositoryInterface;
        $this->tagService = $tagService;
        $this->categoryService = $categoryService;
        $this->form = $form;
    }

    public function index()
    {
        $listTag = $this->tagService->getListTag();
        return view("tag.index", [
            "route" => "tag",
            "action" => "admin-tag",
            "menu" => "menu-open",
            "active" => "active",
            'listTag' => $listTag,
            "tagIds" => $this->tagService->getListTagIds()->pluck('id'),
        ]);
    }

    public function add(Request $request)
    {
        $listsCate = $this->categoryService->getListCategory();
        if ($request->isMethod('post')) {
            $this->form->validate($request, 'TagValidate');
            $tags = $this->tagService->addTag($request);
            if ($tags) {
                return redirect()->route('tag.index')->with("success",__('messages.add_success'));
            }
            return back()->with("error",__('messages.add_error'));
        }
        return view("tag.add", [
            "route" => "tag",
            "action" => "tag-index",
            "menu" => "menu-open",
            "active" => "active",
            "listsCate" => $listsCate,
        ]);
    }

    public function detail(Request $request)
    {
        $infoTag = $this->tagService->getByIdTag($request->id);
        if(!$infoTag) {
            return redirect()->back()->with('error', __('messages.account_not_found'));
        }
        return view("tag.detail", [
            "route" => "tag",
            "action" => "tag-detail",
            "menu" => "menu-open",
            "active" => "active",
            "infoTag" => $infoTag,
        ]);

    }

    public function update(Request $request, $id)
    {
        $listsCate = $this->categoryService->getListCategory();
        $infoTag = $this->tagService->getByIdTag($id);
        if(is_null($infoTag)) {
            return redirect()->back()->with('error', __('messages.account_not_found'));
        }
        if ($request->isMethod('post')) {
            // dd($request->all());
            $this->form->validate($request, 'TagUpdateValidate');
            $updateTag = $this->tagService->updateTag($id, $request);
            // dd($updateTag);
            if ($updateTag) {
                return redirect()->route('tag.index')->with("success",__('messages.add_success'));
            }
            return back()->with("error",__('messages.add_error'));
        }
        return view("tag.update", [
            "route" => "tag",
            "action" => "tag-update",
            "menu" => "menu-open",
            "active" => "active",
            "listsCate" => $listsCate,
            "infoTag" => $infoTag,
        ]);
    }

    public function delete(Request $request)
    {
        $result = $this->tagService->deleteTagByIds($request);
        if ($result) {
            return redirect()->route('tag.index')->with('success', __('messages.delete_success'));
        }
        return redirect()->back()->with('error', __('messages.delete_error'));
    }
}
