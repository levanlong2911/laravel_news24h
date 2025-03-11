<?php

namespace App\Repositories\Interfaces;

interface CategoryRepositoryInterface extends RepositoryInterface
{
    public function getDataListIds($ids);
    public function getListCategory();
}
