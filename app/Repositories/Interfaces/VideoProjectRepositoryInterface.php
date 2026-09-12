<?php

namespace App\Repositories\Interfaces;

use App\Models\Admin;
use App\Models\Article;
use App\Models\VideoProject;

interface VideoProjectRepositoryInterface extends RepositoryInterface
{
    public function findOrCreateByArticleId(Article $article, ?Admin $actor): VideoProject;

    public function firstOrCreateByName(string $name, ?string $subjectId): VideoProject;

    public function listAllWithCounts(?Admin $actor): iterable;

    public function getById(string $id): ?VideoProject;
}
