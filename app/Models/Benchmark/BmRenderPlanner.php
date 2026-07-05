<?php

namespace App\Models\Benchmark;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BmRenderPlanner extends Model
{
    public $timestamps = false;

    protected $table = 'bm_render_planners';

    protected $fillable = ['render_id', 'planner_id', 'fingerprint', 'planner_version'];

    public function render(): BelongsTo
    {
        return $this->belongsTo(BmRender::class, 'render_id');
    }

    public function planner(): BelongsTo
    {
        return $this->belongsTo(BmPlanner::class, 'planner_id');
    }
}
