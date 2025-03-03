<?php

namespace App\Services\Admin;

use App\Enums\Paginate;
use App\Repositories\Interfaces\PostRepositoryInterface;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;

class PostService
{
    private PostRepositoryInterface $postRepository;

    public function __construct(
        PostRepositoryInterface $postRepository
    ) {
        $this->postRepository = $postRepository;
    }


    /**
     * List Role
     *
     * @return mixed
     */
    public function getListPost()
    {
        return $this->postRepository->paginate(Paginate::PAGE->value);
    }

    public function getInfoPost($id)
    {
        return $this->postRepository->find($id);
    }

    public function create($request): Model
    {
        dd(1122);
        // Prepare parameters for creation
        $params = [
            'role_id' => $request->role,
            'name' => $request->name,
            'email' => $request->email,
            'password' => $passwordHash,
        ];

        // Create a new admin using the repository
        return $this->postRepository->create($params);
    }

    /**
     * Xóa một danh mục
     *
     * @param \Illuminate\Http\Request $request
     * @return bool
     */
    // public function delete($request)
    // {
    //     DB::beginTransaction();
    //     try {
    //         $dataCate = $this->categoryRepository->getDataListIds($request->ids);
    //         foreach ($dataCate as $cate) {
    //             // Delete tag
    //             $tags = $this->tagRepositoryInterface->findBy(['category_id' => $cate->id]);
    //             if ($tags) {
    //                 foreach ($tags as $tag) {
    //                     $this->tagService->deletetagByIds(['ids' => $tag['id']]);
    //                 }
    //             }
    //             $cate->delete();
    //         }
    //         DB::commit();
    //         return true;
    //     } catch (Exception $e) {
    //         DB::rollback();
    //         return false;
    //     }
    // }

}
