<?php

namespace App\Repositories\Eloquent;

use App\Enums\Paginate;
use App\Models\Domain;
use App\Repositories\Interfaces\WebsiteRepositoryInterface;

class WebsiteRepository extends BaseRepository implements WebsiteRepositoryInterface
{
    public function getModel(): string
    {
        return Domain::class;
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

    public function getListWebsite()
    {
        return $this->model->newQuery()
                ->orderBy('created_at', 'desc')
                ->paginate(Paginate::PAGE->value);
    }
}
