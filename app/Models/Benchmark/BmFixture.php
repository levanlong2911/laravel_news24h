<?php

namespace App\Models\Benchmark;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BmFixture extends Model
{
    protected $table = 'bm_fixtures';

    protected $fillable = ['slug', 'name', 'scene_category'];

    public function renders(): HasMany
    {
        return $this->hasMany(BmRender::class, 'fixture_id');
    }
}
