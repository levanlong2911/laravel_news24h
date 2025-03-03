<?php

namespace App\Repositories\Interfaces;

interface InforDomainRepositoryInterface extends RepositoryInterface
{
    public function getDataListIds($ids);
    public function getByDomain($domain);
}
