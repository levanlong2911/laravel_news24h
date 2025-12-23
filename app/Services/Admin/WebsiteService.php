<?php

namespace App\Services\Admin;

use App\Enums\Paginate;
use App\Models\ConvertFont;
use App\Repositories\Interfaces\WebsiteRepositoryInterface;
use Exception;
use Illuminate\Support\Facades\DB;

class WebsiteService
{
    private WebsiteRepositoryInterface $websiteRepository;

    public function __construct(
        WebsiteRepositoryInterface $websiteRepository,
    )
    {
        $this->websiteRepository = $websiteRepository;
    }

    public function getListWebsite()
    {
        return $this->websiteRepository->getListWebsite();
    }

    // public function getListFontIds()
    // {
    //     return $this->fontRepository->all();
    // }

    public function addWebsite($request)
    {
        DB::beginTransaction();
        try {
            $host = rtrim(
                preg_replace('#^https?://#', '', $request->host),
                '/'
            );
            $params = [
                'name' => $request->name,
                'host' => $host,
            ];
            dd($this->websiteRepository->create($params));
            $website = $this->websiteRepository->create($params);
            DB::commit();
            return $website;
        } catch (Exception $e) {
            DB::rollback();
            dd($e->getMessage());
            return false;
        }
    }

    // public function getByIdFont($id)
    // {
    //     return $this->fontRepository->find($id);
    // }

    // /**
    //  * Xóa một danh mục
    //  *
    //  * @param \Illuminate\Http\Request $request
    //  * @return bool
    //  */
    // public function deleteFontByIds($request)
    // {
    //     DB::beginTransaction();
    //     try {
    //         $dataFont = $this->fontRepository->getDataListIds($request->ids);
    //         foreach ($dataFont as $font) {
    //             $font->delete();
    //         }
    //         DB::commit();
    //         return true;
    //     } catch (Exception $e) {
    //         DB::rollback();
    //         return false;
    //     }
    // }

}
