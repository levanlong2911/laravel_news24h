<?php

namespace App\Repositories\Interfaces;

use App\Models\VideoSession;

interface VideoSessionRepositoryInterface extends RepositoryInterface
{
    public function listAllWithProjectAndShotCount(): iterable;

    public function findWithProjectAndShots(string $id): VideoSession;

    public function findComposingWithProject(): iterable;

    public function updateOrCreateByCode(string $code, array $attributes): VideoSession;
}
