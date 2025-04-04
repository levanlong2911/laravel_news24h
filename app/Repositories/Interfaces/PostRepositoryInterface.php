<?php

namespace App\Repositories\Interfaces;

interface PostRepositoryInterface extends RepositoryInterface
{
    public function getListPost();
    public function getPostById($id);
    public function update($id, array $data): bool;
    public function getDataListIds($ids);
}
