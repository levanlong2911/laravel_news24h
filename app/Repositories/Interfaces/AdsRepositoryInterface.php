<?php

namespace App\Repositories\Interfaces;

interface AdsRepositoryInterface extends RepositoryInterface
{
    public function getDataListIds($ids);
    public function getListAds();
}
