<?php

namespace App\Repositories\Eloquent;

use App\Models\Tag;
use App\Repositories\Interfaces\TagRepositoryInterface;

class TagRepository extends BaseRepository implements TagRepositoryInterface
{
    public function getModel(): string
    {
        return Tag::class;
    }

    public function getDataListIds($ids)
    {
        return Tag::query()
                ->whereIn("id", $ids)
                ->get();
    }

    public function getTagByCategoryId($categoryId)
    {
        return Tag::where('category_id', $categoryId)->get();
    }
}
