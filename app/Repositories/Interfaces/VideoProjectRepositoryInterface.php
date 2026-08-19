<?php

namespace App\Repositories\Interfaces;

use App\Models\Article;
use App\Models\VideoProject;

interface VideoProjectRepositoryInterface extends RepositoryInterface
{
    public function findOrCreateByArticleId(Article $article): VideoProject;

    public function firstOrCreateByName(string $name, ?string $subjectId): VideoProject;

    public function listAllWithCounts(): iterable;
}
