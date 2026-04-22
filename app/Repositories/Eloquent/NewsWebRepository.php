<?php

namespace App\Repositories\Eloquent;

use App\Enums\Paginate;
use App\Models\NewsWeb;
use App\Repositories\Interfaces\NewsWebRepositoryInterface;

class NewsWebRepository extends BaseRepository implements NewsWebRepositoryInterface
{
    public function getModel(): string
    {
        return NewsWeb::class;
    }

    public function getListNewsWebIds($ids)
    {
        return NewsWeb::query()
                ->whereIn("id", $ids)
                ->get();
    }

    public function getTagByCategoryId($categoryId)
    {
        return NewsWeb::where('category_id', $categoryId)->get();
    }

    public function getListNewsWeb()
    {
        return NewsWeb::query()
                ->orderBy('created_at', 'desc')
                ->paginate(Paginate::PAGE->value);
    }
}
