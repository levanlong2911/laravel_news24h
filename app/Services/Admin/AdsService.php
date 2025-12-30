<?php

namespace App\Services\Admin;

use App\Enums\Paginate;
use App\Repositories\Interfaces\AdsRepositoryInterface;
use Exception;
use Illuminate\Support\Facades\Cache;
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
        DB::beginTransaction();
        try {
            $params = [
                'name' => $request->name,
                'position' => $request->position,
                'domain_id' => $request->domain_id,
                'script' => $request->code,
                'active' => $request->active,
            ];
            $this->adsRepository->create($params);
            DB::commit();
            // ✅ CLEAR CACHE
            $this->clearAdsCacheByData($params);
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
            $ad = $this->adsRepository->find($id);

            // ❌ clear cache OLD
            $this->clearAdsCacheByData([
                'domain_id' => $ad->domain_id,
                'position'  => $ad->position,
            ]);
            $dataAds = [
                "name" => $request->name,
                "position" => $request->position,
                "domain_id" => $request->domain_id,
                "script" => $request->code,
            ];
            $this->adsRepository->update($id, $dataAds);
            DB::commit();
            // ✅ clear cache NEW
            $this->clearAdsCacheByData($dataAds);
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
                // ❌ clear cache trước khi delete
                $this->clearAdsCacheByData([
                    'domain_id' => $ads->domain_id,
                    'position'  => $ads->position,
                ]);
                $ads->delete();
            }
            DB::commit();
            return true;
        } catch (Exception $e) {
            DB::rollback();
            return false;
        }
    }

    protected function clearAdsCacheByData(array $data): void
    {
        if (!empty($data['domain_id'])) {
            Cache::forget("ads:domain:{$data['domain_id']}");
            if (!empty($data['position'])) {
                Cache::forget("ads:{$data['domain_id']}:{$data['position']}");
            }
        }

        // Nếu là GLOBAL ads (domain_id = null)
        Cache::flush(); // an toàn nhất cho ads
    }

}
