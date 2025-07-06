<?php

namespace App\Repositories\Interfaces;

interface FontRepositoryInterface extends RepositoryInterface
{
    public function getDataListIds($ids);
    public function getListFont();
}
