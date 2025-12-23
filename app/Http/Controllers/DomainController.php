<?php

namespace App\Http\Controllers;

use App\Repositories\Interfaces\WebsiteRepositoryInterface;
use App\Services\Admin\WebsiteService;
use Illuminate\Http\Request;

class DomainController extends Controller
{
    private WebsiteRepositoryInterface $websiteRepository;
    private WebsiteService $websiteService;

    public function __construct
    (
        WebsiteRepositoryInterface $websiteRepository,
        WebsiteService $websiteService
    )
    {
        $this->websiteRepository = $websiteRepository;
        $this->websiteService = $websiteService;
    }

    public function index()
    {
        $listWeb = $this->websiteService->getListWebsite();
        return view("website.index", [
            "route" => "website",
            "action" => "website-index",
            "menu" => "menu-open",
            "active" => "active",
            'listWeb' => $listWeb,
            // "fontIds" => $this->fontService->getListFontIds()->pluck('id'),
        ]);
    }

    public function add(Request $request)
    {
        if ($request->isMethod('post')) {
            $addWeb = $this->websiteService->addWebsite($request);
            dd($addWeb);
            if ($addWeb) {
                return redirect()->route('website.index')->with('success', __('messages.add_success'));
            }
            return redirect()->route('website.index')->with('error', __('messages.add_error'));
        }
        return view("website.add", [
            "route" => "website",
            "action" => "website-add",
            "menu" => "menu-open",
            "active" => "active",
        ]);
    }

//     public function detail(Request $request)
//     {
//         $inForFont = $this->fontService->getByIdFont($request->id);
//         if(!$inForFont) {
//             return redirect()->back()->with('error', __('messages.account_not_found'));
//         }
//         return view("convert_font.detail", [
//             "route" => "convert_font",
//             "action" => "convert_font-detail",
//             "menu" => "menu-open",
//             "active" => "active",
//             'inForFont' => $inForFont,
//         ]);
//     }

//     public function update(Request $request, $id)
//     {
//         $inForFont = $this->fontService->getByIdFont($id);
//         if (! $inForFont) {
//             return redirect()->back()->with('error', __('messages.account_not_found'));
//         }
//         if ($request->isMethod('post')) {
//             $params = [
//                 'find' => $request->find,
//                 'replace' => $request->replace,
//             ];
//             $updateFont = $this->fontRepository->update($id, $params);
//             if ($updateFont) {
//                 return redirect()->route('font.index')->with("success",__('messages.add_success'));
//             }
//             return back()->with("error",__('messages.add_error'));
//         }
//         return view("convert_font.update", [
//             "route" => "convert_font",
//             "action" => "convert_font-update",
//             "menu" => "menu-open",
//             "active" => "active",
//             "inForFont" => $inForFont,
//         ]);
//     }

//     public function delete(Request $request)
//     {
//         $del = $this->fontService->deleteFontByIds($request);
//         if ($del) {
//             return redirect()
//                 ->route('font.index')
//                 ->with("success", __("messages.delete_success"));
//         }
//         return redirect()
//         ->route('font.index')
//         ->with("error", __("messages.delete_error"));
//     }
}
