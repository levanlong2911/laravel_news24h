<?php

namespace App\Repositories\Interfaces;

interface PostRepositoryInterface extends RepositoryInterface
{
    public function getListPost($user);
    public function getPostById($id, $user);
    public function update($id, array $data): bool;
    public function getDataListIds($ids);
}
