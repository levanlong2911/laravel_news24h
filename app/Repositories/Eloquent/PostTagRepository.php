<?php

namespace App\Repositories\Eloquent;

use App\Enums\Paginate;
use App\Models\PostTag;
use App\Repositories\Interfaces\PostTagRepositoryInterface;

class PostTagRepository extends BaseRepository implements PostTagRepositoryInterface
{
    public function getModel(): string
    {
        return PostTag::class;
    }

    public function getDataListIds($ids)
    {
        return PostTag::query()
                ->whereIn("id", $ids)
                ->get();
    }
    public function getListByPostId($postId)
    {
        return $this->model
                ->where("post_id", $postId)
                ->get();
    }
}
