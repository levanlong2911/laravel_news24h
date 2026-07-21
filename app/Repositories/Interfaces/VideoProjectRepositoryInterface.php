<?php

namespace App\Repositories\Interfaces;

use App\Models\VideoProject;

interface VideoProjectRepositoryInterface extends RepositoryInterface
{
    public function findOrCreateByArticle(string $title, string $articleId): VideoProject;

    public function firstOrCreateByName(string $name, ?string $subjectId): VideoProject;
}
