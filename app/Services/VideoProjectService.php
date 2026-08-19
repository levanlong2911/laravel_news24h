<?php

namespace App\Services;

use App\Models\VideoProject;
use App\Repositories\Interfaces\ArticleRepositoryInterface;
use App\Repositories\Interfaces\VideoProjectRepositoryInterface;
use App\Services\Admin\ArticleService;
use Exception;
use Illuminate\Support\Facades\DB;

class VideoProjectService
{
    private VideoProjectRepositoryInterface $videoProjectRepository;

    private ArticleRepositoryInterface $articleRepository;

    private ArticleService $articleService;

    public function __construct(
        VideoProjectRepositoryInterface $videoProjectRepository,
        ArticleService $articleService,
        ArticleRepositoryInterface $articleRepository,
    ) {
        $this->videoProjectRepository = $videoProjectRepository;
        $this->articleService = $articleService;
        $this->articleRepository = $articleRepository;
    }

    public function listAll(): iterable
    {
        return $this->videoProjectRepository->listAllWithCounts();
    }

    /** @return array{0: ?VideoProject, 1: string} */
    public function getdataByArticleId(string $articleId)
    {
        $article = $this->articleService->getInfoArticleId($articleId);
        if ($article === null) {
            return [null, 'article_not_found'];
        }

        DB::beginTransaction();
        try {
            $data = $this->videoProjectRepository->findOrCreateByArticleId($article);
            DB::commit();

            return $data;
        } catch (Exception $e) {
            DB::rollback();

            return [null, 'error_occurred'];
        }
    }
}
