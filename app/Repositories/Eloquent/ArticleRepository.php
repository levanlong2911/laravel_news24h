<?php

namespace App\Repositories\Eloquent;

use App\Models\Article;
use App\Repositories\Interfaces\ArticleRepositoryInterface;

class ArticleRepository extends BaseRepository implements ArticleRepositoryInterface
{
    public function getModel(): string
    {
        return Article::class;
    }
}
