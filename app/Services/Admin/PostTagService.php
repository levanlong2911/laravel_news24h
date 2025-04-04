<?php

namespace App\Services\Admin;

use App\Enums\Paginate;
use App\Repositories\Interfaces\PostTagRepositoryInterface;
use Exception;
use Illuminate\Support\Facades\DB;

class PostTagService
{
    private PostTagRepositoryInterface $postTagRepository;

    public function __construct(
        PostTagRepositoryInterface $postTagRepository,
    ) {
        $this->postTagRepository = $postTagRepository;
    }

    public function deleteTagByIds($request)
    {
        DB::beginTransaction();
        try {
            $questionData = $this->postTagRepository->getDataListIds($request->ids);
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
}
