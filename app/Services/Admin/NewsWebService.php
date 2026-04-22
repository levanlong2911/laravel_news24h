<?php

namespace App\Services\Admin;

use App\Enums\Paginate;
use App\Repositories\Interfaces\NewsWebRepositoryInterface;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NewsWebService
{
    private NewsWebRepositoryInterface $newsWebRepository;

    public function __construct(
        NewsWebRepositoryInterface $newsWebRepository,
    ) {
        $this->newsWebRepository = $newsWebRepository;
    }

    public function getListNewsWeb()
    {
        return $this->newsWebRepository->getListNewsWeb();
    }

    public function addNewsWeb($request)
    {
        DB::beginTransaction();
        try {
            $newsWebInputs = array_map('trim', explode(',', $request->url));

            $insertData = [];

            foreach ($newsWebInputs as $url) {

                if (empty($url)) {
                    continue;
                }

                // 👉 đảm bảo URL có scheme
                if (!Str::startsWith($url, ['http://', 'https://'])) {
                    $url = 'https://' . $url;
                }

                $parsed = parse_url($url);

                if (!isset($parsed['host'])) {
                    continue; // bỏ qua URL lỗi
                }

                // 👉 domain
                $domain = preg_replace('/^www\./', '', $parsed['host']);

                // 👉 base_url
                $baseUrl = isset($parsed['path'])
                    ? ltrim($parsed['path'], '/')
                    : null;

                $insertData[] = [
                    "category_id" => $request->category_id,
                    "domain"      => $domain,
                    "base_url"    => $baseUrl,
                ];
            }

            // 👉 insert batch (tối ưu hơn loop create)
            if (!empty($insertData)) {
                $this->newsWebRepository->create($insertData);
            }
            DB::commit();
            return true;
        } catch (\Throwable $e) {
            DB::rollBack();

            // debug nếu cần
            logger()->error($e->getMessage());

            return false;
        }
    }

    public function getByIdTag($id)
    {
        return $this->newsWebRepository->find($id);
    }

    public function updateTag($id, $request)
    {
        DB::beginTransaction();
        try {
            $input = $request->all();

            // Xóa các tag cũ của category liên quan nếu có
            // $this->newsWebRepository->deleteByCategory($input['category_id']);

            // Tách các tag mới bằng dấu phẩy
            $tagNames = array_map('trim', explode(',', $input['tags']));

            // Tạo lại các tag
            foreach ($tagNames as $tagName) {
                $dataTag = [
                    "category_id" => $input['category_id'],
                    "name" => $tagName,
                ];
                $this->newsWebRepository->update($id, $dataTag);
            }

            DB::commit();
            return true;
        } catch (Exception $e) {
            DB::rollback();
            return false;
        }
    }

    public function getListNewsWebIds()
    {
        return $this->newsWebRepository->all();
    }

    // public function deleteTagByIds($request)
    // {
    //     DB::beginTransaction();
    //     try {
    //         $questionData = $this->newsWebRepository->getDataListIds($request->ids);
    //         foreach ($questionData as $question) {
    //             $question->delete();
    //         }
    //         DB::commit();
    //         return true;
    //     } catch (Exception $e) {
    //         DB::rollback();
    //         return false;
    //     }
    // }

    // public function getListTagByCategoryId($categoryId)
    // {
    //     return $this->newsWebRepository->getTagByCategoryId($categoryId);
    // }
}
