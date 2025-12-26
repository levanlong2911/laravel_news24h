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
            "WebsiteIds" => $this->websiteService->getListWebsiteIds()->pluck('id'),
        ]);
    }

    public function add(Request $request)
    {
        if ($request->isMethod('post')) {
            $addWeb = $this->websiteService->addWebsite($request);
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

    public function detail($id)
    {
        $inForWebsite = $this->websiteService->getByIdWebsite($id);
        if(!$inForWebsite) {
            return redirect()->back()->with('error', __('messages.account_not_found'));
        }
        return view("website.detail", [
            "route" => "website",
            "action" => "website-detail",
            "menu" => "menu-open",
            "active" => "active",
            'inForWebsite' => $inForWebsite,
        ]);
    }

    public function update(Request $request, $id)
    {
        $inForWebsite = $this->websiteService->getByIdWebsite($id);
        if (! $inForWebsite) {
            return redirect()->back()->with('error', __('messages.account_not_found'));
        }
        if ($request->isMethod('post')) {
            $params = [
                'name' => $request->name,
                'host' => $request->host,
            ];
            $updateFont = $this->websiteRepository->update($id, $params);
            if ($updateFont) {
                return redirect()->route('website.index')->with("success",__('messages.add_success'));
            }
            return back()->with("error",__('messages.add_error'));
        }
        return view("website.update", [
            "route" => "website",
            "action" => "website-update",
            "menu" => "menu-open",
            "active" => "active",
            "inForWebsite" => $inForWebsite,
        ]);
    }

    public function delete(Request $request)
    {
        $del = $this->websiteService->deleteWebsiteByIds($request);
        if ($del) {
            return redirect()
                ->route('website.index')
                ->with("success", __("messages.delete_success"));
        }
        return redirect()
        ->route('website.index')
        ->with("error", __("messages.delete_error"));
    }
}
