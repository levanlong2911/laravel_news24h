<?php

namespace App\Repositories\Interfaces;

interface NewsWebRepositoryInterface extends RepositoryInterface
{
    public function getListNewsWebIds($ids);
    public function getWebByCategoryId($categoryId);
    public function getListNewsWeb($request = null);
    public function chekDomain($domain, $category_id);
}
