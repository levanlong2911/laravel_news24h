<?php

namespace App\Repositories\Eloquent;

use App\Models\InforDomain;
use App\Repositories\Interfaces\InforDomainRepositoryInterface;
use App\Enums\Paginate;

class InforDomainRepository extends BaseRepository implements InforDomainRepositoryInterface
{
    public function getModel(): string
    {
        return InforDomain::class;
    }

    /**
     * getDataListIds
     *
     * @param  $ids
     *
     * @return string
     */
    public function getDataListIds($ids) {
        return $this->model->whereIn('id', $ids)->get();
    }

    public function getByDomain($domain) {
        return $this->model->where('domain', $domain)->first();
    }

    public function getListDomain()
    {
        return $this->model->newQuery()
                ->orderBy('created_at', 'desc')
                ->paginate(Paginate::PAGE->value);
    }
}
