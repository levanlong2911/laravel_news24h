<?php

namespace App\Repositories\Eloquent;

use App\Enums\Paginate;
use App\Models\ConvertFont;
use App\Repositories\Interfaces\FontRepositoryInterface;

class FontRepository extends BaseRepository implements FontRepositoryInterface
{
    public function getModel(): string
    {
        return ConvertFont::class;
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

    public function getListFont()
    {
        return $this->model->newQuery()
                ->orderBy('created_at', 'desc')
                ->paginate(Paginate::PAGE->value);
    }
}
