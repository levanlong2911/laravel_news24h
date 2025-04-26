<?php

namespace App\Repositories\Eloquent;

use App\Enums\Paginate;
use App\Models\Advertisement;
use App\Repositories\Interfaces\AdsRepositoryInterface;

class AdsRepository extends BaseRepository implements AdsRepositoryInterface
{
    public function getModel(): string
    {
        return Advertisement::class;
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

    public function getListAds()
    {
        return $this->model->newQuery()
                ->orderBy('created_at', 'desc')
                ->paginate(Paginate::PAGE->value);
    }
}
