<?php

namespace App\Repositories\Eloquent;

use App\Models\Article;
use App\Models\Category;
use App\Repositories\Interfaces\CategoryRepositoryInterface;
use App\Enums\Paginate;

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

    public function getListCategory()
    {
        return $this->model->newQuery()
                ->orderBy('created_at', 'desc')
                ->paginate(Paginate::PAGE->value);
    }

    public function getAllCategories()
    {
        return $this->model->newQuery()
                ->orderBy('name', 'asc')
                ->get();
    }

    public function findCategory(?string $categoryId): ?Category
    {
        return $this->model->newQuery()->find($categoryId);
    }

    public function findCategoryOfArticle(Article $article): ?Category
    {
        return $article->category;
    }
}
