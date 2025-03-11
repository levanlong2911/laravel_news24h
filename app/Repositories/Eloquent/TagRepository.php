<?php

namespace App\Repositories\Eloquent;

use App\Models\Tag;
use App\Repositories\Interfaces\TagRepositoryInterface;
use App\Enums\Paginate;

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

    public function getListTag()
    {
        return Tag::query()
                ->orderBy('created_at', 'desc')
                ->paginate(Paginate::PAGE->value);
    }
}
