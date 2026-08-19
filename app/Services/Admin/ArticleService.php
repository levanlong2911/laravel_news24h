<?php

namespace App\Services\Admin;

use App\Repositories\Interfaces\ArticleRepositoryInterface;

class ArticleService
{
    private ArticleRepositoryInterface $articleRepository;

    public function __construct(
        ArticleRepositoryInterface $articleRepository,
    ) {
        $this->articleRepository = $articleRepository;
    }

    public function getInfoArticleId($id)
    {
        return $this->articleRepository->find($id);
    }

}
