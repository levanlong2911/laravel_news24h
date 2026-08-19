<?php

namespace App\Repositories\Interfaces;

interface ArticleRepositoryInterface extends RepositoryInterface
{
    public function getInfoArticleId($id);
}
