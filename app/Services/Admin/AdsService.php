<?php

namespace App\Services\Admin;

use App\Enums\Paginate;
use App\Repositories\Interfaces\AdsRepositoryInterface;
use Exception;
use Illuminate\Support\Facades\DB;

class AdsService
{
    private AdsRepositoryInterface $adsRepository;

    public function __construct(
        AdsRepositoryInterface $adsRepository
    ) {
        $this->adsRepository = $adsRepository;
    }


    /**
     * List Role
     *
     * @return mixed
     */
    public function getListAds()
    {
        return $this->adsRepository->getListAds();
    }

    public function getListAdsIds()
    {
        return $this->adsRepository->all();
    }

    public function addAds($request)
    {
        // dd($request->all());
        DB::beginTransaction();
        try {
            $params = [
                'name' => $request->name,
                'position' => $request->position,
                'script' => $request->code,
                'active' => $request->active,
            ];
            // dd($params);
            $this->adsRepository->create($params);
            DB::commit();
            return true;
        } catch (Exception $e) {
            DB::rollback();
            return false;
        }
    }

    public function getByIdAds($id)
    {
        return $this->adsRepository->find($id);
    }

    public function updateAds($id, $request)
    {
        DB::beginTransaction();
        try {
            $dataAds = [
                "name" => $request->name,
                "position" => $request->position,
                "script" => $request->code,
            ];
            $this->adsRepository->update($id, $dataAds);
            DB::commit();
            return true;
        } catch (Exception $e) {
            DB::rollback();
            return false;
        }
    }

    public function deleteAdsByIds($request)
    {
        DB::beginTransaction();
        try {
            $dataAds = $this->adsRepository->getDataListIds($request->ids);
            foreach ($dataAds as $ads) {
                $ads->delete();
            }
            DB::commit();
            return true;
        } catch (Exception $e) {
            DB::rollback();
            return false;
        }
    }

}
