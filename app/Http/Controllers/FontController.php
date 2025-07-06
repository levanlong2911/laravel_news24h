<?php

namespace App\Http\Controllers;

use App\Repositories\Interfaces\FontRepositoryInterface;
use App\Services\Admin\FontService;
use Illuminate\Http\Request;

class FontController extends Controller
{
    private FontRepositoryInterface $fontRepository;
    private FontService $fontService;

    public function __construct
    (
        FontRepositoryInterface $fontRepository,
        FontService $fontService
    )
    {
        $this->fontRepository = $fontRepository;
        $this->fontService = $fontService;
    }

    public function index()
    {
        $listFont = $this->fontService->getListFont();
        return view("convert_font.index", [
            "route" => "font",
            "action" => "font-index",
            "menu" => "menu-open",
            "active" => "active",
            'listFont' => $listFont,
            "fontIds" => $this->fontService->getListFontIds()->pluck('id'),
        ]);
    }

    public function add(Request $request)
    {
        if ($request->isMethod('post')) {
            $addFont = $this->fontService->addConvertFont($request);
            if ($addFont) {
                return redirect()->route('font.index')->with('success', __('messages.add_success'));
            }
            return redirect()->route('font.index')->with('error', __('messages.add_error'));
        }
        return view("convert_font.add", [
            "route" => "font",
            "action" => "font-add",
            "menu" => "menu-open",
            "active" => "active",
        ]);
    }

    public function detail(Request $request)
    {
        $inForFont = $this->fontService->getByIdFont($request->id);
        if(!$inForFont) {
            return redirect()->back()->with('error', __('messages.account_not_found'));
        }
        return view("convert_font.detail", [
            "route" => "convert_font",
            "action" => "convert_font-detail",
            "menu" => "menu-open",
            "active" => "active",
            'inForFont' => $inForFont,
        ]);
    }

    public function update(Request $request, $id)
    {
        $inForFont = $this->fontService->getByIdFont($id);
        if (! $inForFont) {
            return redirect()->back()->with('error', __('messages.account_not_found'));
        }
        if ($request->isMethod('post')) {
            $params = [
                'find' => $request->find,
                'replace' => $request->replace,
            ];
            $updateFont = $this->fontRepository->update($id, $params);
            if ($updateFont) {
                return redirect()->route('font.index')->with("success",__('messages.add_success'));
            }
            return back()->with("error",__('messages.add_error'));
        }
        return view("convert_font.update", [
            "route" => "convert_font",
            "action" => "convert_font-update",
            "menu" => "menu-open",
            "active" => "active",
            "inForFont" => $inForFont,
        ]);
    }

    public function delete(Request $request)
    {
        $del = $this->fontService->deleteFontByIds($request);
        if ($del) {
            return redirect()
                ->route('font.index')
                ->with("success", __("messages.delete_success"));
        }
        return redirect()
        ->route('font.index')
        ->with("error", __("messages.delete_error"));
    }
}
