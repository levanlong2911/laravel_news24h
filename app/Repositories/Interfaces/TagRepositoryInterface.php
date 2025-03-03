<?php

namespace App\Repositories\Interfaces;

interface TagRepositoryInterface extends RepositoryInterface
{
    public function getDataListIds($ids);
    public function getTagByCategoryId($categoryId);
}
