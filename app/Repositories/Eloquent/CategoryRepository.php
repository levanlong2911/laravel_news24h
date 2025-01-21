<?php

namespace App\Repositories\Eloquent;

use App\Models\Category;
use App\Repositories\Interfaces\CategoryRepositoryInterface;

class CategoryRepository extends BaseRepository implements CategoryRepositoryInterface
{
    public function getModel(): string
    {
        return Category::class;
    }

    /**
     * getDataListIds
     *
     * @param  $ids
     *
     * @return string
     */
    public function getDataListIds($ids) {
        return $this->model->whereIn('id', $ids)->get();
    }
}
