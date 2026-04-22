<?php

namespace App\Repositories\Interfaces;

interface NewsWebRepositoryInterface extends RepositoryInterface
{
    public function getListNewsWebIds($ids);
    // public function getTagByCategoryId($categoryId);
    public function getListNewsWeb();
}
