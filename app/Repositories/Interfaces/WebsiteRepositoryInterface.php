<?php

namespace App\Repositories\Interfaces;

interface WebsiteRepositoryInterface extends RepositoryInterface
{
    public function getDataListIds($ids);
    public function getListWebsite();
}
