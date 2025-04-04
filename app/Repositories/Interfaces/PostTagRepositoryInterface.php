<?php

namespace App\Repositories\Interfaces;

interface PostTagRepositoryInterface extends RepositoryInterface
{
    public function getDataListIds($ids);
    public function getListByPostId($id);
}
