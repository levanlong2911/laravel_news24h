<?php

namespace App\Services\Admin;

use App\Enums\Paginate;
use App\Repositories\Interfaces\CategoryRepositoryInterface;
use App\Repositories\Interfaces\TagRepositoryInterface;
use Exception;
use Illuminate\Support\Facades\DB;

class CategoryService
{
    private CategoryRepositoryInterface $categoryRepository;
    private TagService $tagService;
    private TagRepositoryInterface $tagRepositoryInterface;

    public function __construct(
        CategoryRepositoryInterface $categoryRepository,
        TagService $tagService,
        TagRepositoryInterface $tagRepositoryInterface
    ) {
        $this->categoryRepository = $categoryRepository;
        $this->tagService = $tagService;
        $this->tagRepositoryInterface = $tagRepositoryInterface;
    }


    /**
     * List Role
     *
     * @return mixed
     */
    public function getListCategory()
    {
        return $this->categoryRepository->paginate(Paginate::PAGE->value);
    }

    public function getInfoCate($id)
    {
        return $this->categoryRepository->find($id);
    }

    /**
     * Xóa một danh mục
     *
     * @param \Illuminate\Http\Request $request
     * @return bool
     */
    public function delete($request)
    {
        DB::beginTransaction();
        try {
            $dataCate = $this->categoryRepository->getDataListIds($request->ids);
            foreach ($dataCate as $cate) {
                // Delete tag
                $tags = $this->tagRepositoryInterface->findBy(['category_id' => $cate->id]);
                if ($tags) {
                    foreach ($tags as $tag) {
                        $this->tagService->deletetagByIds(['ids' => $tag['id']]);
                    }
                }
                $cate->delete();
            }
            DB::commit();
            return true;
        } catch (Exception $e) {
            DB::rollback();
            return false;
        }
    }

}
