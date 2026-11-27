<?php

namespace App\Repositories\Interfaces;

use App\Models\Article;
use App\Models\Category;

interface CategoryRepositoryInterface extends RepositoryInterface
{
    public function getDataListIds($ids);
    public function getListCategory();
    public function getAllCategories();

    /**
     * @param  ?string  $categoryId  null still runs the lookup (id is null) and finds nothing
     * @return ?Category the category row, null when it does not exist
     */
    public function findCategory(?string $categoryId): ?Category;

    /**
     * @param  Article  $article  an article a caller read, with or without its category loaded
     * @return ?Category the category of the article through its relation: the loaded one, otherwise read now
     */
    public function findCategoryOfArticle(Article $article): ?Category;
}
