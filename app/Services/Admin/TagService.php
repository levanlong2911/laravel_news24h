<?php

namespace App\Services\Admin;

use App\Enums\Paginate;
use App\Repositories\Interfaces\TagRepositoryInterface;
use Exception;
use Illuminate\Support\Facades\DB;

class TagService
{
    private TagRepositoryInterface $tagRepository;

    public function __construct(
        TagRepositoryInterface $tagRepository,
    ) {
        $this->tagRepository = $tagRepository;
    }

    public function getListTag()
    {
        return $this->tagRepository->paginate(Paginate::PAGE->value);
    }

    public function addTag($request)
    {
        // dd($request->all());
        $input = $request->all();
        DB::beginTransaction();
        try {
            // Split tags by commas and remove extra spaces
            $tagNames = array_map('trim', explode(',', $input['tags']));
            // create data question
            foreach ($tagNames as $tagName) {
                $dataTag = [
                    "category_id" => $input['category_id'],
                    "name" => $tagName,
                ];
                $this->tagRepository->create($dataTag);
            }
            DB::commit();
            return true;
        } catch (Exception $e) {
            DB::rollback();
            return false;
        }
    }

    public function getByIdTag($id)
    {
        return $this->tagRepository->find($id);
    }

    public function updateTag($id, $request)
    {
        DB::beginTransaction();
        try {
            $input = $request->all();

            // Xóa các tag cũ của category liên quan nếu có
            // $this->tagRepository->deleteByCategory($input['category_id']);

            // Tách các tag mới bằng dấu phẩy
            $tagNames = array_map('trim', explode(',', $input['tags']));

            // Tạo lại các tag
            foreach ($tagNames as $tagName) {
                $dataTag = [
                    "category_id" => $input['category_id'],
                    "name" => $tagName,
                ];
                $this->tagRepository->update($id, $dataTag);
            }

            DB::commit();
            return true;
        } catch (Exception $e) {
            DB::rollback();
            return false;
        }
    }

    public function getListTagIds()
    {
        return $this->tagRepository->all();
    }

    public function deleteTagByIds($request)
    {
        DB::beginTransaction();
        try {
            $questionData = $this->tagRepository->getDataListIds($request->ids);
            foreach ($questionData as $question) {
                $question->delete();
            }
            DB::commit();
            return true;
        } catch (Exception $e) {
            DB::rollback();
            return false;
        }
    }

    public function getListTagByCategoryId($categoryId)
    {
        return $this->tagRepository->getTagByCategoryId($categoryId);
    }
}
