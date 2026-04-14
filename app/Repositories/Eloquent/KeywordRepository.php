<?php

namespace App\Repositories\Eloquent;

use App\Repositories\Interfaces\KeywordRepositoryInterface;
use App\Enums\Paginate;
use App\Models\Keyword;

class KeywordRepository extends BaseRepository implements KeywordRepositoryInterface
{
    public function getModel(): string
    {
        return Keyword::class;
    }

    public function getDataListIds($ids)
    {
        return Keyword::query()
                ->whereIn("id", $ids)
                ->get();
    }

    public function getTagByCategoryId($categoryId)
    {
        return Keyword::where('category_id', $categoryId)->get();
    }

    public function getListTag()
    {
        return Keyword::query()
                ->orderBy('created_at', 'desc')
                ->paginate(Paginate::PAGE->value);
    }
}
