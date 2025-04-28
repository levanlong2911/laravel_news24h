<?php

namespace App\Http\Controllers;

use App\Enums\Ads;
use App\Form\AdminCustomValidator;
use App\Repositories\Interfaces\AdsRepositoryInterface;
use App\Services\Admin\AdsService;
use Illuminate\Http\Request;

class AdvertisementController extends Controller
{
    private AdsRepositoryInterface $adsRepository;
    private AdminCustomValidator $form;
    private AdsService $adsService;

    public function __construct
    (
        AdsRepositoryInterface $adsRepository,
        AdminCustomValidator $form,
        AdsService $adsService
    )
    {
        $this->adsRepository = $adsRepository;
        $this->form = $form;
        $this->adsService = $adsService;
    }

    public function index()
    {
        $listAds = $this->adsService->getListAds();
        return view("ads.index", [
            "route" => "ads",
            "action" => "ads-index",
            "menu" => "menu-open",
            "active" => "active",
            'listAds' => $listAds,
            "adsIds" => $this->adsService->getListAdsIds()->pluck('id'),
        ]);
    }

    public function add(Request $request)
    {
        if ($request->isMethod('post')) {
            $this->form->validate($request, 'AdsAddForm');
            $addAds = $this->adsService->addAds($request);
            if ($addAds) {
                return redirect()->route('ads.index')->with('success', __('messages.add_success'));
            }
            return back()->with("error",__('messages.add_error'));
        }
        $positions = Ads::options();
        return view("ads.add", [
            "route" => "ads",
            "action" => "ads-add",
            "menu" => "menu-open",
            "active" => "active",
            "positions" => $positions,
        ]);
    }

    public function detail(Request $request)
    {
        $inforAds = $this->adsService->getByIdAds($request->id);
        return view("ads.detail", [
            "route" => "ads",
            "action" => "ads-detail",
            "menu" => "menu-open",
            "active" => "active",
            "inforAds" => $inforAds,
        ]);

    }

    public function update(Request $request, $id)
    {
        $inforAds = $this->adsService->getByIdAds($id);
        if ($request->isMethod('post')) {
            $this->form->validate($request, 'AdsAddForm');
            $updateAds = $this->adsService->updateAds($id, $request);
            if ($updateAds) {
                return redirect()->route('ads.index')->with("success",__('messages.add_success'));
            }
            return back()->with("error",__('messages.add_error'));
        }
        $positions = Ads::options();
        return view("ads.update", [
            "route" => "ads",
            "action" => "ads-update",
            "menu" => "menu-open",
            "active" => "active",
            "inforAds" => $inforAds,
            "positions" => $positions,
        ]);
    }

    public function delete(Request $request)
    {
        $result = $this->adsService->deleteAdsByIds($request);
        if ($result) {
            return redirect()->route('ads.index')->with('success', __('messages.delete_success'));
        }
        return redirect()->back()->with('error', __('messages.delete_error'));
    }

}
